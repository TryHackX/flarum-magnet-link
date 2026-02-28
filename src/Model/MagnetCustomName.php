<?php

namespace TryHackX\MagnetLink\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $magnet_link_id
 * @property int $post_id
 * @property int $user_id
 * @property string $custom_name
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class MagnetCustomName extends AbstractModel
{
    protected $table = 'magnet_custom_names';

    protected $fillable = ['magnet_link_id', 'post_id', 'user_id', 'custom_name'];

    public $timestamps = true;

    public function magnetLink()
    {
        return $this->belongsTo(MagnetLink::class, 'magnet_link_id');
    }

    /**
     * Znajdź niestandardową nazwę dla konkretnego magneta w konkretnym poście
     */
    public static function findForMagnetAndPost(int $magnetLinkId, int $postId): ?self
    {
        return static::where('magnet_link_id', $magnetLinkId)
            ->where('post_id', $postId)
            ->first();
    }
}
