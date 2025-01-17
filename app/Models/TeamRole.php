<?php

namespace App\Models;

use App\Traits\HasCompositePrimaryKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TeamRole extends ApiModel
{
    use HasCompositePrimaryKey;

    protected $table = 'team_role';
    protected $increments = false;
    protected $primaryKey = ['team_id', 'role_id'];

    /**
     * Find all team roles for a given team.
     * @param $teamId
     * @return \Illuminate\Database\Eloquent\Collection|array|Collection
     */
    public static function findAllForTeam($teamId): \Illuminate\Database\Eloquent\Collection|array|Collection
    {
        return self::where('team_id', $teamId)->get();
    }

    /**
     * Find all the teams with granted roles for a person
     *
     * @param int $personId
     * @return Collection
     */

    public static function findRolesForPerson(int $personId): Collection
    {
        return DB::table('person_team')
            ->select('team.id', 'team.title', 'team_role.role_id')
            ->where('person_id', $personId)
            ->join('team_role', 'person_team.team_id', 'team_role.team_id')
            ->join('team', 'team_role.team_id', 'team.id')
            ->get();
    }

    /**
     * Add a role association to team
     *
     * @param int $teamId
     * @param int $roleId
     * @param string|null $reason
     * @return void
     */

    public static function add(int $teamId, int $roleId, ?string $reason): void
    {
        if ($roleId == Role::ADMIN || $roleId == Role::TECH_NINJA) {
            // Nope, don't allow unchecked privilege escalation.
            return;
        }

        $data = ['team_id' => $teamId, 'role_id' => $roleId];
        if (self::insertOrIgnore($data) == 1) {
            ActionLog::record(Auth::user(), 'team-role-add', $reason, $data);
        }
    }

    /**
     * Remove a role association from team
     *
     * @param int $teamId
     * @param int $roleId
     * @param string|null $reason
     * @return void
     */

    public static function remove(int $teamId, int $roleId, ?string $reason): void
    {
        $data = ['team_id' => $teamId, 'role_id' => $roleId];
        self::where($data)->delete();
        ActionLog::record(Auth::user(), 'team-role-remove', $reason, $data);
    }
}
