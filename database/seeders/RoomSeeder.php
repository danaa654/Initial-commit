<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Source: PAP room inventory list (room_name / room_type / room_group /
     * capacity / floor). room_name here becomes room_code — Room Name was
     * removed from the schema, and these values already work fine as the
     * display code (e.g. "Room 304 (ICT Workshop)").
     *
     * "building" isn't in the source list — every row uses "Main Building"
     * as a placeholder. Update the BUILDING constant below once the real
     * building name/names are known.
     */
    private const BUILDING = 'Main Building';

    public function run(): void
    {
        $rooms = [

            // 1st Floor
            ['room_code' => 'Room 108', 'room_type' => 'Lecture', 'room_groups' => ['BSED'], 'floor' => '1st Floor'],
            ['room_code' => 'Room 109', 'room_type' => 'Lecture', 'room_groups' => ['BSED'], 'floor' => '1st Floor'],
            ['room_code' => 'Room 110', 'room_type' => 'Lecture', 'room_groups' => ['General'], 'floor' => '1st Floor'],
            ['room_code' => 'Room 111', 'room_type' => 'Lecture', 'room_groups' => ['General'], 'floor' => '1st Floor'],
            ['room_code' => 'Ground Zero', 'room_type' => 'Laboratory', 'room_groups' => ['COC'], 'floor' => '1st Floor'],

            // 2nd Floor
            ['room_code' => 'MEZ 110', 'room_type' => 'Lecture', 'room_groups' => ['General'], 'floor' => '2nd Floor'],
            ['room_code' => 'MEZ 111', 'room_type' => 'Lecture', 'room_groups' => ['General'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 201 (Forensic BSCRIM Lab)', 'room_type' => 'Laboratory', 'room_groups' => ['COC'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 202 (Forensic Chemistry Lab)', 'room_type' => 'Laboratory', 'room_groups' => ['COC'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 203', 'room_type' => 'Lecture', 'room_groups' => ['COC'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 204', 'room_type' => 'Lecture', 'room_groups' => ['COC'], 'floor' => '2nd Floor'],
            ['room_code' => 'Room 205', 'room_type' => 'Lecture', 'room_groups' => ['COC'], 'floor' => '2nd Floor'],

            // 3rd Floor
            ['room_code' => 'Room 301 (BSHM Lab)', 'room_type' => 'Laboratory', 'room_groups' => ['BSHM'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 302 (Travel Agency Office)', 'room_type' => 'Laboratory', 'room_groups' => ['BSTM'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 303', 'room_type' => 'Lecture', 'room_groups' => ['General'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 304 (ICT Workshop)', 'room_type' => 'Laboratory', 'room_groups' => ['BSIT'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 305 (Lab 2)', 'room_type' => 'Laboratory', 'room_groups' => ['BSIT'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 306 (Lab 1)', 'room_type' => 'Laboratory', 'room_groups' => ['BSIT'], 'floor' => '3rd Floor'],
            ['room_code' => 'Room 307 (Accre)', 'room_type' => 'Lecture', 'room_groups' => ['General'], 'floor' => '3rd Floor'],

            // 4th Floor
            ['room_code' => 'FBS/BSHM Function Hall', 'room_type' => 'Laboratory', 'room_groups' => ['BSHM'], 'floor' => '4th Floor'],
            ['room_code' => 'Foods/Cookery Lab', 'room_type' => 'Laboratory', 'room_groups' => ['BSHM'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 401 Functional Hall', 'room_type' => 'Lecture', 'room_groups' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 403', 'room_type' => 'Lecture', 'room_groups' => ['General'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 404 (BSCRIM1)', 'room_type' => 'Lecture', 'room_groups' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 405 (BSCRIM2)', 'room_type' => 'Lecture', 'room_groups' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 406 (BSCRIM3)', 'room_type' => 'Lecture', 'room_groups' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 407 (BSCRIM4)', 'room_type' => 'Lecture', 'room_groups' => ['COC'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 408 (BSTM)', 'room_type' => 'Lecture', 'room_groups' => ['BSTM'], 'floor' => '4th Floor'],
            ['room_code' => 'Room 409 (BSTM)', 'room_type' => 'Lecture', 'room_groups' => ['BSTM'], 'floor' => '4th Floor'],

        ];

        foreach ($rooms as $data) {

            $room = Room::updateOrCreate(
                ['room_code' => $data['room_code']],
                [
                    'room_type' => $data['room_type'],
                    'building' => self::BUILDING,
                    'floor' => $data['floor'],
                    'capacity' => 30,
                    'active' => true,
                ]
            );

            // Replace whatever programs this room had (idempotent — safe
            // to re-run the seeder without ending up with duplicate rows).
            $room->roomGroups()->delete();

            foreach ($data['room_groups'] as $roomGroup) {
                $room->roomGroups()->create([
                    'room_group' => $roomGroup,
                ]);
            }

        }
    }
}