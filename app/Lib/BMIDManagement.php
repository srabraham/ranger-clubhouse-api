<?php

namespace App\Lib;

use App\Models\AccessDocument;
use App\Models\Bmid;
use App\Models\Person;
use App\Models\PersonPosition;
use App\Models\PersonSlot;
use App\Models\Position;
use App\Models\Slot;
use App\Models\TraineeStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BMIDManagement
{
    // Person columns to return when doing a BMID sanity check
    const INSANE_PERSON_COLUMNS = [
        'id',
        'callsign',
        'email',
        'first_name',
        'last_name',
        'status'
    ];

    /**
     * Sanity check the BMIDs.
     *
     * - Find any BMID for a person who has non-Training shifts starting before the access date
     * - Find any BMID for a person who does not have a WAP or a Staff Credential
     * - Find any BMID for a person who has reduced-price-ticket and a WAP with an access date before the box office opens.
     *
     * @param int $year
     * @return array[]
     */

    public static function sanityCheckForYear(int $year): array
    {
        /*
         * Find people who have signed up shifts starting before their WAP access date
         */
        $slotIds = DB::table('slot')
            ->whereYear('begins', $year)
            ->where('begins', '>', "$year-08-15")
            ->whereNotIn('position_id', [Position::TRAINING, Position::TRAINER, Position::TRAINER_ASSOCIATE, Position::TRAINER_UBER])
            ->pluck('id');


        $ids = DB::table('person_slot')
            ->select('person_slot.person_id')
            ->join('access_document', 'access_document.person_id', 'person_slot.person_id')
            ->whereIntegerInRaw('person_slot.slot_id', $slotIds)
            ->whereIn('access_document.type', [AccessDocument::WAP, AccessDocument::STAFF_CREDENTIAL])
            ->whereIn('access_document.status', AccessDocument::CHECK_STATUSES)
            ->where('access_document.access_any_time', false)
            ->groupBy('person_slot.person_id')
            ->pluck('person_slot.person_id');

        $shiftsBeforeWap = Person::whereIntegerInRaw('id', $ids)
            ->where('status', '!=', Person::ALPHA)
            ->orderBy('callsign')
            ->get(self::INSANE_PERSON_COLUMNS);

        /*
         * Find people who signed up early shifts yet do not have a WAP
         */

        $slotIds = DB::table('slot')
                ->whereYear('begins', $year)
                ->where('begins', '>', "$year-08-10")
                ->pluck('id');

        $ids = DB::table('person_slot')
            ->select('person_slot.person_id')
            ->whereIntegerInRaw('person_slot.slot_id', $slotIds)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('access_document')
                    ->whereColumn('access_document.person_id', 'person_slot.person_id')
                    ->whereIn('type', [AccessDocument::WAP, AccessDocument::STAFF_CREDENTIAL])
                    ->whereIn('status', AccessDocument::CHECK_STATUSES);
            })->groupBy('person_slot.person_id')
            ->pluck('person_slot.person_id');

        $shiftsNoWap = Person::whereIntegerInRaw('id', $ids)
            ->where('status', '!=', Person::ALPHA)
            ->orderBy('callsign')
            ->get(self::INSANE_PERSON_COLUMNS);

        $boxOfficeOpenDate = setting('TAS_BoxOfficeOpenDate', true);

        $ids = DB::table('access_document as wap')
            ->join('access_document as rpt', function ($j) {
                $j->on('wap.person_id', 'rpt.person_id')
                    ->where('rpt.type', AccessDocument::RPT)
                    ->whereIn('rpt.status', [AccessDocument::QUALIFIED, AccessDocument::CLAIMED, AccessDocument::SUBMITTED]);
            })->join('person', function ($j) {
                $j->on('person.id', 'wap.person_id')
                    ->where('person.status', '!=', Person::ALPHA);
            })->where('wap.type', AccessDocument::WAP)
            ->whereIn('wap.status', [AccessDocument::QUALIFIED, AccessDocument::CLAIMED, AccessDocument::SUBMITTED])
            ->where('wap.access_date', '<', $boxOfficeOpenDate)
            ->groupBy('wap.person_id')
            ->pluck('wap.person_id');

        $rptBeforeBoxOfficeOpens = Person::whereIntegerInRaw('id', $ids)
            ->orderBy('callsign')
            ->get(self::INSANE_PERSON_COLUMNS);

        return [
            [
                'type' => 'shifts-before-access-date',
                'people' => $shiftsBeforeWap,
            ],

            [
                'type' => 'shifts-no-wap',
                'people' => $shiftsNoWap,
            ],

            [
                'type' => 'rpt-before-box-office',
                'box_office' => $boxOfficeOpenDate,
                'people' => $rptBeforeBoxOfficeOpens
            ]
        ];
    }

    /**
     * Retrieve a category of BMIDs to manage
     *
     * 'alpha': All status Prospective & Alpha
     * 'signedup': Current Rangers who are signed up for a shift starting Aug 10th or later
     * 'submitted':  status submitted BMIDs
     * 'printed': status printed BMIDs
     * 'nonprint': status issues and/or do-not-print BMIDs
     * default: any BMIDs with showers, meals, any access time, or a WAP date prior to the WAP default.
     *
     * @param int $year
     * @param string $filter
     * @return Bmid[]|array|Collection
     */

    public static function retrieveCategoryToManage(int $year, string $filter)
    {
        switch ($filter) {
            case 'alpha':
                // Find all alphas & prospective
                $ids = Person::whereIn('status', [Person::ALPHA, Person::PROSPECTIVE])
                    ->get('id')
                    ->pluck('id');
                break;

            case 'signedup':
                // Find any vets who are signed up and/or passed training
                $slotIds = Slot::whereYear('begins', $year)
                    ->where('begins', '>=', "$year-08-10")
                    ->pluck('id');

                $signedUpIds = PersonSlot::whereIntegerInRaw('slot_id', $slotIds)
                    ->join('person', function ($j) {
                        $j->whereRaw('person.id=person_slot.person_id');
                        $j->whereIn('person.status', Person::ACTIVE_STATUSES);
                    })
                    ->distinct('person_slot.person_id')
                    ->pluck('person_id')
                    ->toArray();

                $slotIds = Slot::join('position', 'position.id', '=', 'slot.position_id')
                    ->whereYear('begins', $year)
                    ->where('position.type', Position::TYPE_TRAINING)
                    ->get(['slot.id'])
                    ->pluck('id')
                    ->toArray();

                $trainedIds = TraineeStatus::join('person', function ($j) {
                    $j->whereRaw('person.id=trainee_status.person_id');
                    $j->whereIn('person.status', Person::ACTIVE_STATUSES);
                })
                    ->whereIntegerInRaw('slot_id', $slotIds)
                    ->where('passed', 1)
                    ->distinct('trainee_status.person_id')
                    ->pluck('trainee_status.person_id')
                    ->toArray();
                $ids = array_merge($trainedIds, $signedUpIds);
                break;

            case 'submitted':
            case 'printed':
                // Any BMIDs already submitted or printed out
                $ids = Bmid::where('year', $year)
                    ->where('status', $filter)
                    ->pluck('person_id')
                    ->toArray();
                break;

            case 'nonprint':
                // Any BMIDs held back
                $ids = Bmid::where('year', $year)
                    ->whereIn('status', [BMID::ISSUES, BMID::DO_NOT_PRINT])
                    ->pluck('person_id')
                    ->toArray();
                break;

            case 'no-shifts':
                // Any BMIDs held back
                $ids = Bmid::where('year', $year)
                    ->whereRaw("NOT EXISTS (SELECT 1 FROM person_slot JOIN slot ON person_slot.slot_id=slot.id WHERE bmid.person_id=person_slot.person_id AND YEAR(slot.begins)=$year LIMIT 1)")
                    ->pluck('person_id')
                    ->toArray();
                break;

            default:
                // Find the special people.
                // "You're good enough, smart enough, and doggone it, people like you."
                $wapDate = setting('TAS_DefaultWAPDate');

                $specialIds = BMID::where('year', $year)
                    ->where(function ($q) {
                        $q->whereNotNull('title1');
                        $q->orWhereNotNull('title2');
                        $q->orWhereNotNull('title3');
                        $q->orWhereNotNull('meals');
                        $q->orWhere('showers', true);
                    })
                    ->get(['person_id'])
                    ->pluck('person_id')
                    ->toArray();

                $adIds = AccessDocument::whereIn('type', [AccessDocument::STAFF_CREDENTIAL, AccessDocument::WAP])
                    ->whereIn('status', [
                        AccessDocument::BANKED,
                        AccessDocument::QUALIFIED,
                        AccessDocument::CLAIMED,
                        AccessDocument::SUBMITTED
                    ])->where(function ($q) use ($wapDate) {
                        // Any AD where the person can get in at any time
                        //   OR
                        // The access date is lte WAP access
                        $q->where('access_any_time', 1);
                        $q->orWhere(function ($q) use ($wapDate) {
                            $q->whereNotNull('access_date');
                            $q->where('access_date', '<', "$wapDate 00:00:00");
                        });
                    })
                    ->distinct('person_id')
                    ->get(['person_id'])
                    ->pluck('person_id')
                    ->toArray();

                $provisionIds = AccessDocument::whereIn('access_document.type', [AccessDocument::WET_SPOT, ...AccessDocument::EAT_PASSES])
                    ->whereIn('access_document.status', [AccessDocument::QUALIFIED, AccessDocument::CLAIMED, AccessDocument::SUBMITTED])
                    ->distinct('person_id')
                    ->get(['person_id'])
                    ->pluck('person_id')
                    ->toArray();


                $ids = array_merge($specialIds, $adIds);
                $ids = array_merge($provisionIds, $ids);
                $ids = array_unique($ids);
                break;
        }

        return BMID::findForPersonIds($year, $ids);
    }

    public static function setBMIDTitles()
    {
        $year = current_year();

        $bmidTitles = [];
        $bmids = [];

        foreach (Bmid::BADGE_TITLES as $positionId => $title) {
            // Find folks who have the position
            $people = PersonPosition::where('position_id', $positionId)->pluck('person_id');

            foreach ($people as $personId) {
                $bmid = $bmids[$personId] ?? null;
                if ($bmid == null) {
                    $bmid = Bmid::findForPersonManage($personId, $year);
                    // cache the BMID record - multiple titles might be set
                    $bmids[$personId] = $bmid;
                }

                $bmid->{$title[0]} = $title[1];

                if (!isset($bmids[$personId])) {
                    $bmidTitles[$personId] = [];
                }
                $bmidTitles[$personId][$title[0]] = $title[1];
            }
        }

        $badges = [];

        foreach ($bmids as $bmid) {
            $bmid->auditReason = 'maintenance - set BMID titles';
            $bmid->saveWithoutValidation();

            $person = $bmid->person;
            $title = $bmidTitles[$bmid->person_id];
            $badges[] = [
                'id' => $personId,
                'callsign' => $person->callsign,
                'status' => $person->status,
                'title1' => $title['title1'] ?? null,
                'title2' => $title['title2'] ?? null,
                'title3' => $title['title3'] ?? null,
            ];
        }

        usort($badges, fn($a, $b) => strcasecmp($a['callsign'], $b['callsign']));

        return $badges;
    }
}