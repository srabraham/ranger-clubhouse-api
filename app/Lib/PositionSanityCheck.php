<?php

namespace App\Lib;

use InvalidArgumentException;

class PositionSanityCheck
{
    /*
     * Report on problematic position assignments and roles
     *
     * "STOP THE INSANITY" -- Susan Powders, 1990s self proclaimed exercise "guru"
     *  & peroxide enthusiast.
     */

    const CHECKERS = [
        'green_dot' => 'App\Lib\PositionSanityCheck\GreenDotCheck',
        'management_role' => 'App\Lib\PositionSanityCheck\ManagementYearRoundCheck',
        'management_onplaya_role' => 'App\Lib\PositionSanityCheck\ManagementOnPlayaCheck',
        'shiny_pennies' => 'App\Lib\PositionSanityCheck\ShinnyPenniesCheck',
        'deactivated_positions' => 'App\Lib\PositionSanityCheck\DeactivatedPositionCheck',
    ];

    public static function issues(): array
    {
        foreach (self::CHECKERS as $name => $checker) {
            $insanity[$name] = call_user_func("$checker::issues");
        }

        $insanity['shiny_penny_year'] = current_year();

        return $insanity;
    }

    /**
     * Repair position / role problems
     *
     * @param string $repair - the name of the thing to repair 'green_dot', 'management_role', 'shiny_penny'
     * @param array $peopleIds - list of person ids to repair. Ids are assumed to exist.
     * @return array
     */

    public static function repair(string $repair, array $peopleIds, array $options): array
    {
        if (!array_key_exists($repair, self::CHECKERS)) {
            throw new InvalidArgumentException("Unknown repair action [$repair]");
        }

        $class = self::CHECKERS[$repair];
        return call_user_func("$class::repair", $peopleIds, $options);
    }

}
