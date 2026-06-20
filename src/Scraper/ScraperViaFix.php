<?php
/**
 * Scrapeer, a tiny PHP library that lets you scrape
 * HTTP(S) and UDP trackers for torrent information.
 *
 * This file is extensively based on Johannes Zinnau's
 * work, which can be found at https://goo.gl/7hyjde
 *
 * Licensed under a Creative Commons
 * Attribution-ShareAlike 3.0 Unported License
 * http://creativecommons.org/licenses/by-sa/3.0
 *
 * @package Scrapeer
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TryHackX HARDENED scraper — cURL-owy wariant {@see Scraper}.
 * Autoładowany jako `Scrapeer\ScraperViaFix` (PSR-4). Wybierany ustawieniem admina
 * `tryhackx-magnet-link.scraper_engine = 'hardened'` (DOMYŚLNY).
 *
 * Zamyka okno DNS-rebindingu/SSRF z silnika klasycznego przez PINOWANIE IP: host jest
 * rozwiązywany + walidowany zakresowo RAZ, a faktyczne połączenie jest wymuszone na to
 * konkretne IP — bez drugiego zapytania DNS przy connect.
 *   - HTTP/HTTPS (po TCP): cURL z CURLOPT_RESOLVE (host→zpinowane IP), CURLOPT_PROTOCOLS
 *     ograniczone do HTTP(S), ścisła weryfikacja TLS (SNI/cert na ORYGINALNĄ nazwę).
 *   - UDP: socket połączony do zpinowanego IP, dual-stack (AF_INET6 dla IPv6).
 *
 * Nadpisane są TYLKO trzy metody połączeń — cała logika protokołu BitTorrent i
 * parsowania jest DZIEDZICZONA z {@see Scraper} (jedno źródło prawdy, bez kopiuj-wklej).
 * ─────────────────────────────────────────────────────────────────────────────
 */

namespace Scrapeer;

/**
 * Hardened ("via-fix") wariant {@see Scraper} oparty o cURL + przypięcie IP.
 */
class ScraperViaFix extends Scraper {

    /**
     * Gdy true — pomiń kontrolę zakresową publicznego IP (opt-in admina
     * `allow_private_trackers`). Pinowanie nadal działa (host rozwiązany RAZ).
     *
     * @var bool
     */
    private $allow_private = false;

    /**
     * Pozwól łączyć się z prywatnymi/zarezerwowanymi IP (opt-in admina). Domyślnie false.
     *
     * @param bool $allow
     */
    public function set_allow_private( $allow ) {
        $this->allow_private = (bool) $allow;
    }

    /**
     * Rozwiąż $host do JEDNEGO publicznego, routowalnego IP („pin"), użytego do
     * faktycznego połączenia — TO SAMO rozwiązanie, które przechodzi kontrolę zakresową,
     * więc nie ma okna na DNS-rebinding. Dual-stack: sprawdza A (IPv4) i AAAA (IPv6).
     * Zwraca null, gdy host się nie rozwiązuje albo którykolwiek adres jest
     * prywatny/zarezerwowany (chyba że allow_private = true).
     *
     * @param string $host Nazwa hosta lub literał IP.
     * @return string|null Zpinowane IP albo null (zablokowany/nierozwiązywalny).
     */
    private function resolve_pinned_ip( $host ) {
        $host = trim( $host, '[]' ); // zdejmij nawiasy literału IPv6

        // Literał IP: waliduj wprost, bez DNS.
        if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return ( $this->allow_private || $this->ip_is_public( $host ) ) ? $host : null;
        }

        $ips = array();
        $v4 = @gethostbynamel( $host );
        if ( is_array( $v4 ) ) {
            $ips = $v4;
        }
        $aaaa = @dns_get_record( $host, DNS_AAAA );
        if ( is_array( $aaaa ) ) {
            foreach ( $aaaa as $rec ) {
                if ( ! empty( $rec['ipv6'] ) ) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        if ( empty( $ips ) ) {
            return null; // martwy host
        }

        if ( $this->allow_private ) {
            return $ips[0]; // admin dopuścił prywatne — pinuj pierwsze rozwiązane IP
        }

        // Odrzuć, jeśli KTÓRYKOLWIEK adres jest prywatny/zarezerwowany, a następnie
        // ZPINUJ pierwszy publiczny do połączenia.
        $pinned = null;
        foreach ( $ips as $ip ) {
            if ( ! $this->ip_is_public( $ip ) ) {
                return null;
            }
            if ( null === $pinned ) {
                $pinned = $ip;
            }
        }

        return $pinned;
    }

    /**
     * True, gdy $ip jest publiczny i routowalny (nie prywatny/zarezerwowany), v4 lub v6.
     *
     * @param string $ip
     * @return bool
     */
    private function ip_is_public( $ip ) {
        return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /**
     * Pojedyncze żądanie cURL z TWARDYM przypięciem IP (CURLOPT_RESOLVE), ścisłą
     * weryfikacją TLS i limitem rozmiaru odpowiedzi.
     *
     * @param string $url Pełny docelowy URL (http/https).
     * @return array [body (string), headers (array), http_status (int)]
     * @throws \Exception Gdy brak cURL, host zablokowany lub błąd sieci.
     */
    private function single_curl_request( $url ) {
        if ( ! function_exists( 'curl_init' ) ) {
            // Silnik hardened wymaga cURL — fail-closed (TrackerScraper złapie wyjątek →
            // brak statystyk, zamiast cichego obejścia pinowania). Bez cURL użyj silnika
            // 'classic'. Ścieżka UDP nie wymaga cURL.
            throw new \Exception( 'cURL extension is required for the hardened scraper engine.' );
        }

        $parts = parse_url( $url );
        $host = isset( $parts['host'] ) ? $parts['host'] : '';
        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
        $default_port = ( 'https' === $scheme ) ? 443 : 80;
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : $default_port;

        // SSRF / DNS-rebinding: rozwiąż i zwaliduj host RAZ.
        $ip = $this->resolve_pinned_ip( $host );
        if ( null === $ip ) {
            throw new \Exception( 'Blocked or unresolvable tracker host (' . $host . ').' );
        }

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $this->timeout );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false ); // przekierowania ręcznie + re-pinowane
        // Tylko HTTP(S) — blokuje file://, gopher://, dict:// itp. (anty-SSRF).
        curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );

        // PIN: zmuś cURL, by dla host:port użył zweryfikowanego IP; Host/SNI/cert
        // pozostają na oryginalnej nazwie. IPv6 w nawiasach (format CURLOPT_RESOLVE).
        $formatted_ip = ( false !== strpos( $ip, ':' ) ) ? '[' . $ip . ']' : $ip;
        curl_setopt( $ch, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $formatted_ip ) );

        $headers = array();
        $body = '';

        curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function ( $ch, $header_line ) use ( &$headers ) {
            $headers[] = $header_line;
            return strlen( $header_line );
        } );

        // Ochrona przed memory exhaustion — przerwij transfer po przekroczeniu limitu.
        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function ( $ch, $data ) use ( &$body ) {
            $len = strlen( $data );
            if ( ( strlen( $body ) + $len ) > self::MAX_RESPONSE_BYTES ) {
                $body .= substr( $data, 0, self::MAX_RESPONSE_BYTES - strlen( $body ) );
                return 0; // zwrócenie 0 przerywa transfer (CURLE_WRITE_ERROR)
            }
            $body .= $data;
            return $len;
        } );

        curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_errno = curl_errno( $ch );
        $curl_error = curl_error( $ch );
        curl_close( $ch );

        // Tolerujemy TYLKO celowe przerwanie zapisu (ucięcie zbyt dużej odpowiedzi).
        // Każdy inny błąd sieciowy to wyjątek.
        if ( 0 !== $curl_errno && CURLE_WRITE_ERROR !== $curl_errno ) {
            throw new \Exception( 'Tracker request failed: ' . $curl_error . ' (' . $host . ':' . $port . ').' );
        }

        return array( $body, $headers, $http_code );
    }

    /**
     * Scrape HTTP(S) — nadpisuje {@see Scraper::http_request} cURL-em z przypięciem IP.
     * Przekierowania 3xx obsługiwane ręcznie i re-pinowane co hop.
     *
     * @param string $query Pełny URL scrape.
     * @param string $host Oryginalny host (do komunikatów błędów).
     * @param int    $port Port.
     * @return string Surowa odpowiedź bencode.
     * @throws \Exception
     */
    protected function http_request( $query, $host, $port ) {
        $url = $query;
        $redirects_left = $this->max_redirects;
        $response = '';

        while ( true ) {
            list( $response, $headers, $status ) = $this->single_curl_request( $url );

            if ( $status >= 300 && $status < 400 && $redirects_left > 0 ) {
                $location = $this->http_header_value( $headers, 'Location' );
                if ( '' === $location ) {
                    break;
                }

                $next = $this->resolve_redirect_url( $url, $location );
                $next_host = (string) parse_url( $next, PHP_URL_HOST );
                if ( '' === $next_host ) {
                    throw new \Exception( 'Invalid redirect target from tracker (' . $host . ').' );
                }

                // SSRF: walidacja hosta redirectu (właściwe pinowanie nastąpi w kolejnej
                // iteracji pętli — single_curl_request rozwiąże + zpinuje nowy host).
                if ( null !== $this->host_validator && ! call_user_func( $this->host_validator, $next_host ) ) {
                    throw new \Exception( 'Blocked tracker redirect to non-public host (' . $next_host . ').' );
                }

                $url = $next;
                $redirects_left--;
                continue;
            }

            break;
        }

        if ( ! is_string( $response ) || ! str_starts_with( $response, 'd5:filesd20:' ) ) {
            throw new \Exception( 'Invalid scrape response (' . $host . ':' . $port . ').' );
        }

        return $response;
    }

    /**
     * Announce HTTP(S) — nadpisuje {@see Scraper::http_announce} tym samym pinowanym
     * cURL-em (audyt: ta ścieżka nie może omijać przypięcia IP).
     *
     * @param array  $infohashes
     * @param string $protocol
     * @param string $host
     * @param int    $port
     * @param string $passkey
     * @return string
     * @throws \Exception
     */
    protected function http_announce( $infohashes, $protocol, $host, $port, $passkey ) {
        $tracker_url = $protocol . '://' . $host . ':' . $port . $passkey;

        $response_data = '';
        foreach ( $infohashes as $infohash ) {
            $query = $tracker_url . '/announce?info_hash=' . urlencode( pack( 'H*', $infohash ) );

            list( $response ) = $this->single_curl_request( $query );

            if ( ! str_starts_with( $response, 'd8:completei' ) ||
                str_starts_with( $response, 'd8:completei0e10:downloadedi0e10:incompletei1e' ) ) {
                continue;
            }

            $ben_hash = '20:' . pack( 'H*', $infohash ) . 'd';
            $response_data .= $ben_hash . $response;
        }

        return $response_data;
    }

    /**
     * UDP — nadpisuje {@see Scraper::udp_create_connection}: przypięcie IP + dual-stack.
     * Rozwiązanie RAZ, connect do tego IP (brak rebindingu); AF_INET6 dla IPv6
     * (oryginał był AF_INET = tylko IPv4). Protokół scrape UDP nie przenosi nazwy hosta,
     * więc połączenie do IP jest w pełni przezroczyste.
     *
     * @param string $host
     * @param int    $port
     * @return resource
     * @throws \Exception
     */
    protected function udp_create_connection( $host, $port ) {
        $ip = $this->resolve_pinned_ip( $host );
        if ( null === $ip ) {
            throw new \Exception( 'Blocked or unresolvable UDP tracker host (' . $host . ').' );
        }
        $family = ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) ? AF_INET6 : AF_INET;

        if ( false === ( $socket = @socket_create( $family, SOCK_DGRAM, SOL_UDP ) ) ) {
            throw new \Exception( "Couldn't create socket." );
        }

        $timeout = $this->timeout;
        socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array( 'sec' => $timeout, 'usec' => 0 ) );
        socket_set_option( $socket, SOL_SOCKET, SO_SNDTIMEO, array( 'sec' => $timeout, 'usec' => 0 ) );
        if ( false === @socket_connect( $socket, $ip, $port ) ) {
            throw new \Exception( "Couldn't connect to socket." );
        }

        return $socket;
    }
}
