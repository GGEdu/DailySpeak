<?php

use App\Models\Debate;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel(Debate::class, function (User $user, Debate $debate) {
    return $user->can('view', $debate);
});
