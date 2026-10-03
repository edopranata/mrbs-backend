<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $floor = fake()->numberBetween(1, 9);

        return [
            'code' => 'R'.$floor.fake()->unique()->bothify('??#'),
            'name' => 'Ruang Rapat '.fake()->unique()->word(),
            'floor' => $floor,
            'capacity' => fake()->numberBetween(4, 30),
            'facilities' => ['Proyektor', 'AC', 'Whiteboard'],
            'description' => null,
            'color' => fake()->hexColor(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
