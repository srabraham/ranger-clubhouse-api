<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use App\Http\Controllers\ApiController;

use App\Helpers\SqlHelper;

use App\Models\LambasePhoto;
use App\Models\Person;
use App\Models\PersonLanguage;
use App\Models\PersonMentor;
use App\Models\PersonMessage;
use App\Models\PersonPosition;
use App\Models\PersonRole;
use App\Models\PersonYearInfo;
use App\Models\Photo;
use App\Models\Position;
use App\Models\Role;
use App\Models\Timesheet;
use App\Models\Training;

use App\Mail\AccountCreationMail;
use App\Mail\WelcomeMail;

class PersonController extends ApiController
{
    /*
     * Search and display person rows.
     */

    public function index()
    {
        $params = request()->validate([
            'query'            => 'sometimes|string',
            'search_fields'    => 'sometimes|string',
            'statuses'         => 'sometimes|string',
            'exclude_statuses' => 'sometimes|string',
            'limit'            => 'sometimes|integer',
            'offset'           => 'sometimes|integer',
            'basic'            => 'sometimes|boolean',
        ]);

        $results = Person::findForQuery($params);
        $people = $results['people'];
        $meta = [ 'limit' => $results['limit'], 'total' => $results['total'] ];

        if (@$params['basic']) {
            if (!$this->userHasRole([ Role::ADMIN, Role::MANAGE, Role::VC, Role::MENTOR, Role::TRAINER ])) {
                throw new \InvalidArgumentException("Not authorized for basic search.");
            }

            $rows = [];
            foreach ($results['people'] as $person) {
                $rows[] = [
                    'id'              => $person->id,
                    'callsign'        => $person->callsign,
                    'status'          => $person->status,
                    'first_name'      => $person->first_name,
                    'last_name'       => $person->last_name,
                    'email'           => $person->email,
                    'user_authorized' => $person->user_authorized,
                ];
            }

            return response()->json([ 'person' => $rows, 'meta' => $meta ]);
        } else {
            return $this->toRestFiltered($people, $meta, 'person');
        }
    }

    /*
     * Search

    /*
     * Create a person
     * TODO
     */
    public function store(Request $request)
    {
        $this->authorize('store');
    }

    /*
     * Show a specific person - include roles, and languages.
     */
    public function show(Person $person)
    {
        $this->authorize('view', $person);
        $personId = $person->id;
        $person->retrieveRoles();

        $person->languages = PersonLanguage::retrieveForPerson($personId);

        $personId = $this->user->id;

        return $this->toRestFiltered($person);
    }

    /*
     * Update a person record. Also update the person_language table at the same time.
     */

    public function update(Request $request, Person $person)
    {
        $this->authorize('update', $person);

        $this->fromRestFiltered($person);
        $person->retrieveRoles();

        // The callsign approval can only be unset if the person is not a ranger
        if ($person->isDirty('callsign_approved')
            && !$person->callsign_approved
            && !in_array($person->status, [ "prospective", "past prospective", "alpha"])
        ) {
            throw new \InvalidArgumentException('callsign_approved can not be unset once approved and status is not prospecitve, past prospecitve, or alpha.');
        }

        $statusChanged = false;
        if ($person->isDirty('status')) {
            $statusChanged = true;
            $newStatus = $person->status;
            $oldStatus = $person->getOriginal('status');
        }

        if (!$person->save()) {
            return $this->restError($person);
        }

        if ($person->languages !== null) {
            PersonLanguage::updateForPerson($person->id, $person->languages);
        }

        // Track changes
        $changes = $person->getChanges();
        if (!empty($changes)) {
            $this->log('person-update', 'person update', $changes, $person->id);
        }

        if ($statusChanged) {
            $person->changeStatus($newStatus, $oldStatus, 'person update');
            $person->save();
        }

        return $this->toRestFiltered($person);
    }

    /*
     * Remove the person from the clubhouse
     */
    public function destroy(Person $person)
    {
        $this->authorize('delete', $person);

        DB::transaction(
            function () use ($person) {
                $personId = $person->id;

                DB::update('UPDATE slot SET signed_up = signed_up - 1 WHERE id IN (SELECT slot_id FROM person_slot WHERE person_id=?)', [ $personId ]);

                $tables = [
                    'access_document_change',
                    'access_document_delivery',
                    'access_document',
                    'action_logs',
                    'alert_person',
                    'asset_person',
                    'bmid',
                    'broadcast_message',
                    'contact_log',
                    'manual_review',
                    'mentee_status',
                    'person_language',
                    'person_mentor',
                    'person_message',
                    'person_position',
                    'person_role',
                    'person_slot',
                    'timesheet',
                ];

                foreach ($tables as $table) {
                    DB::table($table)->where('person_id', $personId)->delete();
                }

                // Farewell, parting is such sweet sorrow . . .
                $person->delete();
            }
        );

        $this->log(
            'person-delete', 'Person delete',
            [
                    'callsign' => $person->callsign,
                    'status' => $person->status,
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
            ], $person->id
        );

        return $this->restDeleteSuccess();
    }

    /*
     * Return the person's training status, BMID & radio privs
     * for a given year.
     */

    public function yearInfo(Person $person)
    {
        $year = $this->getYear();
        $yearInfo = PersonYearInfo::findForPersonYear($person->id, $year);
        if ($yearInfo) {
            return response()->json(['year_info' => $yearInfo]);
        }

        return $this->restError('The person or year could not be found.', 404);
    }

    /*
     * Change password
     */

    public function password(Request $request, Person $person)
    {
        $this->authorize('password', $person);

        /*
         * Require the old password if person == user
         * and are not an admin.
         * The policy will only allow a change issued by the user or an admin
         */

        $requireOld = $this->isUser($person) && !$this->userHasRole(Role::ADMIN);

        $rules = [
              'password' => 'required|confirmed',
              'password_confirmation' => 'required'
          ];

        if ($requireOld) {
            $rules['password_old'] = 'required';
        }

        $passwords = $request->validate($rules);

        if ($requireOld && !$person->isValidPassword($passwords['password_old'])) {
            return $this->restError('The old password does not match.', 422);
        }

        $this->log('person-password', 'Password changed', null, $person->id);
        $person->changePassword($passwords['password']);

        return $this->success();
    }

    /*
     * Obtain the photo url, and upload link if enabled.
     * (may also download the photo from lambase)
     */

    public function photo(Request $request, Person $person)
    {
        $this->authorize('view', $person);

        $source = config('clubhouse.PhotoSource');
        if ($source == 'Lambase') {
            $storeLocal = config('clubhouse.PhotoStoreLocally') == true;

            $lambase = new LambasePhoto($person);
            $status = $lambase->getStatus();

            $errorMessage = null;
            $imageUrl = null;

            if (!$status['error']) {
                $imageStatus = LambasePhoto::statusToCode($status['status'], $status['data']);

                if ($storeLocal) {
                    if ($status['data']) {
                        // should the photo be downloaded?
                        if ($lambase->downloadNeeded($status['image_hash'])) {
                            if (!$lambase->downloadImage($status['image'])) {
                                $imageStatus = 'error';
                                $imageUrl = null;
                                $errorMessage = 'Failed to download image';
                            } else {
                                $imageUrl = Photo::imageUrlForPerson($person->id);
                            }
                        } else {
                            $imageUrl = Photo::imageUrlForPerson($person->id);
                        }
                    } else {
                        // Missing file, delete local copy
                        $lambase->deleteLocal();
                    }
                }
            } else {
                // Something went horribly wrong.
                $imageStatus = 'error';
                $errorMessage = $status['message'];
            }

            if ($imageStatus != 'error' && $imageStatus != 'missing' && $imageUrl == null) {
                $imageUrl = $lambase->getImageUrl($status['image']);
            }

            if (config('clubhouse.PhotoUploadEnable')) {
                $uploadUrl = $lambase->getUploadUrl();
            } else {
                $uploadUrl = null;
            }

            $results = [
               'photo_url'    => $imageUrl,
               'photo_status' => $imageStatus,
               'upload_url'   => $uploadUrl,
               'source'       => 'lambase',
               'message'      => $errorMessage,
            ];
        } else if ($source == 'test') {
            $results = [
                'source'       => 'local',
                'photo_status' => 'approved',
                'photo_url'    => 'images/test-mugshot.jpg',
                'upload_url'   => null,
            ];
        } else {
            // Local photo source
            $imageUrl = Photo::imageUrlForPerson($person->id);
            $results = [
                'source'       => 'local',
                'photo_status' => 'approved',
                'photo_url'    => $imageUrl,
                'upload_url'   => null,
            ];
        }

        return response()->json([ 'photo' => $results]);
    }

     /*
      * Retrieve the positions held
      */

    public function positions(Request $request, Person $person)
    {
        $params = request()->validate([
            'include_training'   => 'sometimes|boolean',
            'year'               => 'required_if:include_training,true|integer'
        ]);

        $this->authorize('view', $person);

        if (@$params['include_training']) {
            $positions = Training::findPositionsWithTraining($person->id, $params['year']);
        } else {
            $positions = PersonPosition::findForPerson($person->id);
        }

        return response()->json([ 'positions' =>  $positions]);
    }
      /*
       * Update the positions held
       */

    public function updatePositions(Request $request, Person $person)
    {
        $this->authorize('updatePositions', $person);
        $params = request()->validate(
            [
            'position_ids'  => 'required|array',
            'position_ids.*' => 'sometimes|integer'
            ]
        );

        $personId = $person->id;

        $positionIds = $params['position_ids'];
        $positions = PersonPosition::findForPerson($personId);
        $newIds = [];
        $deleteIds = [];

        // Find the new ids
        foreach ($positionIds as $id) {
            $found = false;
            foreach ($positions as $position) {
                if ($position->id == $id) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $newIds[] = $id;
            }
        }

        // Find the ids to be deleted
        foreach ($positions as $position) {
            if (!in_array($position->id, $positionIds)) {
                $deleteIds[] = $position->id;
            }
        }

        // Mass delete the old ids
        if (count($deleteIds) > 0) {
            PersonPosition::where('person_id', $personId)->whereIn('position_id', $deleteIds)->delete();
        }

        // Insert the new ids
        foreach ($newIds as $id) {
            PersonPosition::insert([ 'person_id' => $personId, 'position_id' => $id ]);
        }

        if (count($deleteIds) > 0 || count($newIds) > 0) {
            $this->log('person-position', 'Positions update', [ 'delete' => $deleteIds, 'add' => $newIds ]);
        }

        return response()->json([ 'positions' => PersonPosition::findForPerson($personId) ]);
    }

    /*
    * Retrieve the roles held, and return a list of role ids
    */

    public function roles(Request $request, Person $person)
    {
        $this->authorize('view', $person);

        return response()->json([ 'roles' => PersonRole::findRolesForPerson($person->id) ]);
    }

    /*
     * Update the roles held
     */

    public function updateRoles(Request $request, Person $person)
    {
        $this->authorize('updateRoles', $person);
        $params = request()->validate(
            [
            'role_ids'  => 'required|array',
            'role_ids.*' => 'sometimes|integer'
            ]
        );

        $personId = $person->id;

        $roleIds = $params['role_ids'];
        $existingRoles = PersonRole::findRoleIdsForPerson($personId);
        $newIds = [];
        $deleteIds = [];

        // Find the new ids
        foreach ($roleIds as $id) {
            if (!in_array($id, $existingRoles)) {
                $newIds[] = $id;
            }
        }

        // Find the ids to be deleted
        foreach ($existingRoles as $id) {
            if (!in_array($id, $roleIds)) {
                $deleteIds[] = $id;
            }
        }

        // Mass delete the old ids
        if (count($deleteIds) > 0) {
            PersonRole::where('person_id', $personId)->whereIn('role_id', $deleteIds)->delete();
        }

        // Insert the new ids
        foreach ($newIds as $id) {
            PersonRole::insert([ 'person_id' => $personId, 'role_id' => $id ]);
        }

        if (count($deleteIds) > 0 || count($newIds) > 0) {
            $this->log('person-role', 'Roles update', [ 'delete' => $deleteIds, 'add' => $newIds ]);
        }

        return response()->json([ 'roles' => PersonRole::findRolesForPerson($personId) ]);
    }

    /*
     * Return the years rangered in array form.
     */

    public function years(Person $person)
    {
        $this->authorize('view', $person);
        return response()->json([ 'years' => Timesheet::yearsRangered($person->id)]);
    }

     /*
      * Return the count of  unread messages
      */

    public function unreadMessageCount(Person $person)
    {
        $this->authorize('view', $person);

        return response()->json(['unread_message_count' => PersonMessage::countUnread($person->id)]);
    }

     /*
      * Find out if the person is a trainer, and/or has mentored. Used to construct
      * training info
      */

    public function teacher(Person $person)
    {
        $this->authorize('view', $person);

        $data = [
            'is_trainer'     => $this->user->hasRole([Role::ADMIN, Role::TRAINER]),
            'is_art_trainer' => $this->user->hasRole([Role::ART_TRAINER, Role::ADMIN]),
            'is_mentor'      => $this->user->hasRole([Role::MENTOR, Role::ADMIN]),
            'have_mentored'  => PersonMentor::haveMentees($this->user->id),
        ];

        /*
         * In the future the ART training positions might be limited to
         * a specific set intead of everything for all ART_TRAINERs.
         */

        if ($data['is_art_trainer']) {
            $data['arts'] = Position::findAllTrainings(true);
        }

        return response()->json(['teacher' => $data]);
    }

    /*
     * Calculate how many earned credits for a given year
     */

    public function credits(Person $person)
    {
        $data = request()->validate([
            'year' => 'integer|required'
        ]);

        $this->authorize('view', $person);

        return response()->json( [
            'credits' => Timesheet::earnedCreditsForYear($person->id, $data['year'])
        ]);
    }

    /*
     * Find the person's mentees
     */

    public function mentees(Person $person)
    {
        $this->authorize('mentees', $person);
        return response()->json(['mentees' => PersonMentor::retrieveAllForPerson($person->id) ]);
    }

    /*
     * Retrieve a person's mentors
     */

    public function mentors(Person $person)
    {
        $this->authorize('mentors', $person);

        return response()->json([ 'mentors' => PersonMentor::findMentorsForPerson($person->id) ]);
    }

    /**
     * Register a new account, only supporting auditors right now.
     *
     * Note: method does not require authorization/login. see routes/api.php
     */

    public function register() {
        $params = request()->validate([
            'intent'            => 'required|string',
            'person.email'      => 'required|email',
            'person.password'   => 'required|string',
            'person.first_name' => 'required|string',
            'person.mi'         => 'sometimes|string',
            'person.last_name'  => 'required|string',
            'person.street1'    => 'required|string',
            'person.street2'    => 'sometimes|string',
            'person.apt'        => 'sometimes|string',
            'person.city'       => 'required|string',
            'person.state'      => 'required|string',
            'person.country'    => 'required|string',
            'person.status'     => 'required|string',
        ]);

        $accountCreateEmail = config('clubhouse.AccountCreationEmail');

        $intent = $params['intent'];

        $person = new Person;
        $person->fill($params['person']);

        if ($person->status != 'auditor') {
            throw new \InvalidArgumentException('Only the auditor status is allowed currently for registration.');
        }

        if (Person::emailExists($person->email)) {
            // An account already exists with the same email..
            Mail::to($accountCreateEmail)->send(new AccountCreationMail('failed', 'duplicate email', $person, $intent));
            $this->log('person-register-fail', 'duplicate email', [ 'person' => $params['person'] ]);
            return response()->json([ 'status' => 'email-exists' ]);
        }

        // make the callsign for an auditor.
        if ($person->status == 'auditor') {
            $person->makeAuditorCallsign();
        }

        $person->create_date = SqlHelper::now();

        if (!$person->save()) {
            // Ah, crapola. Something nasty happened that shouldn't have.
            Mail::to($accountCreateEmail)->send(new AccountCreationMail('failed', 'database creation error', $person, $intent));
            $this->log('person-register-fail', 'database creation error', [ 'person' => $person, 'errors' => $person->getErrors() ]);
            return $this->restError($person);
        }

        // Log account creation
        Mail::to($accountCreateEmail)->send(new AccountCreationMail('success', 'account created', $person, $intent));
        $this->log('person-register', 'account registration', null, $person->id);

        // Set the password
        $person->changePassword($params['person']['password']);

        // Setup the default roles & positions
        PersonRole::resetRoles($person->id, 'account registration', Person::ADD_NEW_USER);
        PersonPosition::resetPositions($person->id, 'account registration', Person::ADD_NEW_USER);

        // Send a welcome email to the person if not an auditor
        if ($person->status != 'auditor' && config('clubhouse.SendWelcomeEmail')) {
            Mail::to($person->email)->send(new WelcomeMail($person));
        }

        return response()->json([ 'status' => 'success' ]);
    }
}
