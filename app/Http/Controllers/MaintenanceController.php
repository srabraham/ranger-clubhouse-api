<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonPosition;
use App\Models\Position;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends ApiController
{
    public function __construct()
    {
        parent::__construct();

        if (app()->runningInConsole()) {
            return;
        }

        $this->authorize('isAdmin');
    }

    /*
     * Add any missing positions  to "active" Rangers, and to Prospectives
     *
     * TODO: Fold this into the Position Sanity Checker
     */

    public function updatePositions()
    {
        $allRangersPositions = Position::where('all_rangers', true)
            ->where('active', true)
            ->orderBy('title')
            ->get()
            ->keyBy('id');

        /*
         * Only look at those Rangers who meet all of the following criteria:
         * - Are a "live" status (active, inactive, retired)
         * - Already have a dirt positon assigned
         * - Do not have ALL of the "All Rangers" positions
         */

        $actives = Person::select('id', 'callsign', 'status')
            ->with('person_position')
            ->whereIn('person.status', Person::ACTIVE_STATUSES)
            ->whereRaw('EXISTS (SELECT 1 FROM person_position WHERE person_id=person.id AND position_id=? LIMIT 1)', [Position::DIRT])
            ->whereRaw('(SELECT COUNT(*) FROM person_position WHERE person_id=person.id AND position_id IN (' . implode(',', $allRangersPositions->pluck('id')->toArray()) . ')) != ' . $allRangersPositions->count())
            ->orderBy('callsign')
            ->get();

        $activePeople = [];
        foreach ($actives as $person) {
            $addPositions = [];
            $ids = [];
            $haveIds = $person->person_position->pluck('position_id')->toArray();

            foreach ($allRangersPositions as $position) {
                if (!in_array($position->id, $haveIds)) {
                    $addPositions[] = [
                        'id' => $position->id,
                        'title' => $position->title,
                    ];
                    $ids[] = $position->id;
                }
            }

            if (!empty($addPositions)) {
                $activePeople[] = [
                    'id' => $person->id,
                    'callsign' => $person->callsign,
                    'status' => $person->status,
                    'positions_add' => $addPositions
                ];

                PersonPosition::addIdsToPerson($person->id, $ids, 'update position maintenance');
            }
        }

        $newUserPositions = Position::where('new_user_eligible', true)
            ->orderBy('title')
            ->get();

        $prospectives = Person::where('status', Person::PROSPECTIVE)
            ->with(['person_position', 'person_position.position'])
            ->orderBy('callsign')
            ->get();

        $prospectivePeople = [];
        $newUserPositionIds = $newUserPositions->pluck('id')->toArray();

        foreach ($prospectives as $person) {
            $addPositions = [];
            $haveIds = $person->person_position->pluck('position_id')->toArray();
            $addIds = [];
            foreach ($newUserPositions as $position) {
                if (!in_array($position->id, $haveIds)) {
                    $addPositions[] = [
                        'id' => $position->id,
                        'title' => $position->title,
                    ];
                    $addIds[] = $position->id;
                }
            }

            $removePositions = [];
            $removeIds = [];
            foreach ($person->person_position as $pp) {
                if (!in_array($pp->position_id, $newUserPositionIds)) {
                    $position = $pp->position;
                    $removePositions[] = [
                        'id' => $position->id,
                        'title' => $position->title,
                    ];
                    $removeIds[] = $position->id;
                }
            }

            if (!empty($addPositions) || !empty($removePositions)) {
                $prospectivePeople[] = [
                    'id' => $person->id,
                    'callsign' => $person->callsign,
                    'status' => $person->status,
                    'positions_add' => $addPositions,
                    'positions_remove' => $removePositions
                ];

                if (!empty($addPositions)) {
                    PersonPosition::addIdsToPerson($person->id, $addIds, 'maintenance - update position');
                }

                if (!empty($removePositions)) {
                    PersonPosition::removeIdsFromPerson($person->id, $removePositions, 'maintenance - update position');
                }
            }
        }

        return response()->json([
            'actives' => $activePeople,
            'prospectives' => $prospectivePeople,
        ]);
    }

    /*
     * Mark everyone who is on site as off site.
     */

    public function markOffSite()
    {
        // Grab the folks who are going to be marked as off site
        $people = Person::select('id', 'callsign')->where('on_site', true)->get();

        // .. and actually mark them. (would be nice if Eloquent could have a get() then update() feature)
        Person::where('on_site', true)->update(['on_site' => false]);

        // Log what was done
        foreach ($people as $person) {
            $this->log('person-update', 'maintenance - marked off site', ['id' => $person->id, 'on_site' => [true, false]], $person->id);
        }

        return response()->json(['count' => $people->count()]);
    }

    /**
     * Reset all PNVs (Alphas/Bonks/Prospecitves) to Past Prospective status, reset callsign, and marked unapproved.
     * @return JsonResponse
     */

    public function resetPNVs(): JsonResponse
    {
        $pnvs = Person::whereIn('status', [Person::ALPHA, Person::BONKED, Person::PROSPECTIVE, Person::PROSPECTIVE_WAITLIST])
            ->orderBy('callsign')
            ->get();

        $people = [];
        foreach ($pnvs as $person) {
            $result = [
                'id' => $person->id,
                'callsign' => $person->callsign,
                'status' => $person->status
            ];

            if ($person->resetCallsign()) {
                $person->callsign_approved = false;
                $oldStatus = $person->status;
                $person->status = Person::PAST_PROSPECTIVE;
                $person->auditReason = 'maintenance - pnv reset';
                $person->saveWithoutValidation();
                $person->changeStatus(Person::PAST_PROSPECTIVE, $oldStatus, 'maintenance - pnv reset');
                $result['callsign_reset'] = $person->callsign;
            } else {
                $result['error'] = 'Cannot reset callsign';
            }
            $people[] = $result;
        }

        return response()->json(['people' => $people]);
    }

    /**
     * Reset all Past Prospectives with approved callsigns to LastFirstYY and mark the callsign as unapproved.
     * @return JsonResponse
     */

    public function resetPassProspectives(): JsonResponse
    {
        $pp = Person::where('status', Person::PAST_PROSPECTIVE)
            ->where('callsign_approved', true)
            ->orderBy('callsign')
            ->get();

        $people = [];
        foreach ($pp as $person) {
            $result = [
                'id' => $person->id,
                'callsign' => $person->callsign,
                'status' => $person->status
            ];

            if ($person->resetCallsign()) {
                $person->callsign_approved = false;
                $changes = $person->getChangedValues();
                $person->auditReason = 'maintenance - past prospective reset';
                $person->saveWithoutValidation();
                $result['callsign_reset'] = $person->callsign;
            } else {
                $result['error'] = 'Cannot reset callsign';
            }
            $people[] = $result;
        }

        return response()->json(['people' => $people]);
    }

    /**
     * Archive Clubhouse messages
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function archiveMessages(): JsonResponse
    {
        prevent_if_ghd_server('Message archiving');

        $year = current_year();
        $prevYear = $year - 1;
        $table = "person_message_$prevYear";

        $exists = count(DB::select("SHOW TABLES LIKE '$table'"));

        if ($exists) {
            return response()->json(['status' => 'archive-exists', 'year' => $prevYear]);
        }

        DB::statement("CREATE TABLE $table AS SELECT * FROM person_message");
        DB::statement("DELETE FROM person_message WHERE YEAR(timestamp) < $year");

        $this->log('archive-messages', "archive messages $prevYear into table $table", null);

        return response()->json(['status' => 'success', 'year' => $prevYear]);
    }
}
