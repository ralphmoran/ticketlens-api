<?php

namespace App\Http\Controllers\Console\Admin;

use App\Models\Group;
use App\Models\SlackDigestSchedule;
use App\Models\SlackIntegration;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class DigestsController extends Controller
{
    public function index(Request $request): Response
    {
        $group = $this->resolveGroup($request);

        $digestSchedules = $group
            ? SlackDigestSchedule::where('group_id', $group->id)
                ->orderBy('day_of_week')
                ->orderBy('deliver_at')
                ->paginate(25)
                ->through(fn ($s) => [
                    'id'                => $s->id,
                    'day_of_week'       => $s->day_of_week,
                    'deliver_at'        => $s->deliver_at,
                    'timezone'          => $s->timezone,
                    'target_type'       => $s->target_type,
                    'target_id'         => $s->target_id,
                    'target_label'      => $s->target_label,
                    'active'            => $s->active,
                    'last_delivered_at' => $s->last_delivered_at?->toIso8601String(),
                ])
            : new LengthAwarePaginator([], 0, 25, 1);

        $integration = $group
            ? SlackIntegration::where('group_id', $group->id)->whereNotNull('channel_id')->first()
            : null;

        return Inertia::render('Console/Admin/Digests', [
            'group'           => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'slackChannel'    => $integration ? ['id' => $integration->channel_id, 'name' => $integration->channel_name] : null,
            'digestSchedules' => $digestSchedules,
        ]);
    }

    private function resolveGroup(Request $request): ?Group
    {
        $user = $request->user();

        if ($user->is_owner) {
            $groupId = $request->integer('group_id');
            return $groupId ? Group::find($groupId) : null;
        }

        return $user->ownedGroup;
    }
}
