<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);
        $baseSku = strtoupper(Str::slug($name, '-'));

        // Random simple variations example (tweak to your needs)
        $variations = [
            'color' => $this->faker->randomElement(['Black','White','Blue','Red','Gray']),
            'size'  => $this->faker->randomElement(['XS','S','M','L','XL']),
        ];

        $price = $this->faker->randomFloat(2, 49, 6999);
        $sale  = $this->faker->boolean(40) ? $price - $this->faker->randomFloat(2, 10, 400) : null;
        if ($sale !== null && $sale < 1) $sale = null;

        return [
            'name'            => Str::title($name),
            'description'     => $this->faker->sentence(12),
            'sku'             => $baseSku . '-' . $this->faker->unique()->numerify('###'),
            'price'           => $price,
            'sale_price'      => $sale,
            'stock_quantity'  => $this->faker->numberBetween(10, 300),
            'min_stock_alert' => $this->faker->numberBetween(3, 20),
            'is_active'       => true,
            'is_featured'     => $this->faker->boolean(20),
            'images'          => [$this->faker->imageUrl(800, 800, 'technics', true)], // replaced in ProductSeeder
            'variations'      => $variations,
            'weight'          => null,
            'dimensions'      => null,
        ];
    }

    public function withImage(string $url): self
    {
        return $this->state(fn () => ['images' => [$url]]);
    }
}
