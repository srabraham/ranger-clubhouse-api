<?php

namespace Tests;

use App\Models\Person;
use App\Models\PersonPosition;
use App\Models\PersonRole;
use App\Models\PositionCredit;
use App\Models\Role;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    public $user;

    public function setUp(): void
    {
        parent::setUp();

        // force garbage collection before each test
        // Faker triggers a memory allocation bug.
        gc_collect_cycles();

        Setting::$cache = [];
        PositionCredit::clearCache();

        // Set the time to the beginning of the year
        Carbon::setTestNow(date('Y-01-01 12:34:56'));
    }

    public function createUser()
    {
        $this->user = Person::factory()->create();
        if (!$this->user->id) {
            throw new RuntimeException("Failed to create signed in user." . json_encode($this->user->getErrors()));
        }
    }


    public function signInUser()
    {
        $this->createUser();
        $this->actingAs($this->user);
    }


    public function addRole($roles, $user = null)
    {
        if (!$user) {
            $user = $this->user;
        }

        if (!is_array($roles)) {
            $roles = [$roles];
        }

        $rows = [];
        foreach ($roles as $role) {
            $rows[] = [
                'person_id' => $user->id,
                'role_id' => $role,
            ];
        }

        PersonRole::insert($rows);
    }


    public function addPosition($positions, $user = null)
    {
        if (!$user) {
            $user = $this->user;
        }

        if (!is_array($positions)) {
            $positions = [$positions];
        }

        foreach ($positions as $p) {
            PersonPosition::factory()->create(
                [
                    'person_id' => $user->id,
                    'position_id' => $p,
                ]
            );
        }
    }

    public function addAdminRole($user = null)
    {
        $this->addRole(Role::ADMIN, $user);
    }

    public function setting($name, $value)
    {
        Setting::where('name', $name)->delete();
        Setting::insert(['name' => $name, 'value' => $value]);
    }
}
