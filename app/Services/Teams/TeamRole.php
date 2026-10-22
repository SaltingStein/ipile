<?php

namespace App\Services\Teams;

/**
 * A member's role inside one team. The owner is not a membership role: ownership is stored on the
 * team itself and implies every permission.
 */
enum TeamRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Member => 'Member',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Can rename the team, invite and remove members, and change roles.',
            self::Member => 'Can see the team and its members.',
        };
    }

    /**
     * Whether this role may manage the team's settings, members and invitations.
     */
    public function canManageTeam(): bool
    {
        return $this === self::Admin;
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
        ], self::cases());
    }
}
