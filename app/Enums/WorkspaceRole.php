<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function canManage(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
