<?php

namespace TryHackX\MagnetLink\Listener;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keeps magnet links out of outgoing emails.
 *
 * Flarum's notification emails quote the post: the plain-text part its *source* — the whole
 * `[magnet]magnet:?…[/magnet]` link — and the HTML part its rendered card (a "Loading…"
 * placeholder that never loads in a mail client). Their recipients are picked by the
 * notification (followers of the discussion, mentioned users), not by the View magnet links
 * permission: a suspended member, say, still gets reply emails, and so would everyone outside
 * the group if the permission were narrowed. Both parts get a short note instead, so the link
 * is only ever handed out by the permission-checked API — the way the forum does it (which
 * also keeps the click count honest).
 *
 * Runs after core's MutateEmail (extension listeners are registered once the app has booted),
 * so the values it puts back into the message are already in place.
 */
class HideMagnetsInEmails
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        $note = null;

        $text = $message->getTextBody();
        if (is_string($text) && stripos($text, '[magnet]') !== false) {
            $note ??= $this->note();
            $message->text(
                preg_replace('/\[magnet\].*?\[\/magnet\]/is', '[' . $note . ']', $text) ?? $text,
                $message->getTextCharset() ?? 'utf-8'
            );
        }

        $html = $message->getHtmlBody();
        if (is_string($html) && str_contains($html, 'class="MagnetLink"')) {
            $note ??= $this->note();
            $message->html(
                preg_replace(
                    '/<span class="MagnetLink"[^>]*>.*?<\/span>\s*<\/span>/s',
                    '<em>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</em>',
                    $html
                ) ?? $html,
                $message->getHtmlCharset() ?? 'utf-8'
            );
        }
    }

    private function note(): string
    {
        // NotificationMailer has already switched the translator to the recipient's locale.
        return $this->translator->trans('tryhackx-magnet-link.email.magnet_hidden');
    }
}
