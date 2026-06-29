<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('group.{groupId}', function (User $user, int $groupId) {
    if ($user->is_owner) {
        return true;
    }

    if (!in_array($user->tier, ['team', 'pro'], true)) {
        return false;
    }

    return $user->groups()->where('groups.id', $groupId)->exists();
});
