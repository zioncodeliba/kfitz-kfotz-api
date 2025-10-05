<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $categories = [
            ['name' => 'Electronics', 'description' => 'Electronic devices and gadgets', 'parent' => null],
            ['name' => 'Clothing', 'description' => 'Fashion and apparel', 'parent' => null],
            ['name' => 'Home & Kitchen', 'description' => 'Appliances, furniture, kitchen', 'parent' => null],
            ['name' => 'Sports & Outdoors', 'description' => 'Sporting & outdoor equipment', 'parent' => null],

            // subs
            ['name' => 'Smartphones', 'description' => 'Mobile phones and accessories', 'parent' => 'Electronics'],
            ['name' => 'Laptops', 'description' => 'Personal and professional laptops', 'parent' => 'Electronics'],
            ['name' => 'Cameras', 'description' => 'Digital cameras and accessories', 'parent' => 'Electronics'],
            ['name' => 'Headphones', 'description' => 'Headphones and audio devices', 'parent' => 'Electronics'],

            ['name' => 'Men', 'description' => 'Men clothing and fashion', 'parent' => 'Clothing'],
            ['name' => 'Women', 'description' => 'Women clothing and fashion', 'parent' => 'Clothing'],
            ['name' => 'Kids', 'description' => 'Clothing for kids and babies', 'parent' => 'Clothing'],
            ['name' => 'Shoes', 'description' => 'Shoes and footwear', 'parent' => 'Clothing'],

            ['name' => 'Furniture', 'description' => 'Living/bedroom/office furniture', 'parent' => 'Home & Kitchen'],
            ['name' => 'Cookware', 'description' => 'Pots, pans, cooking accessories', 'parent' => 'Home & Kitchen'],
            ['name' => 'Home Decor', 'description' => 'Interior decoration items', 'parent' => 'Home & Kitchen'],
            ['name' => 'Appliances', 'description' => 'Home and kitchen appliances', 'parent' => 'Home & Kitchen'],

            ['name' => 'Fitness', 'description' => 'Exercise equipment and accessories', 'parent' => 'Sports & Outdoors'],
            ['name' => 'Camping', 'description' => 'Tents, sleeping bags, camping gear', 'parent' => 'Sports & Outdoors'],
            ['name' => 'Cycling', 'description' => 'Bikes and cycling accessories', 'parent' => 'Sports & Outdoors'],
            ['name' => 'Team Sports', 'description' => 'Football, basketball, etc.', 'parent' => 'Sports & Outdoors'],
        ];

        $byName = [];

        // Create roots
        foreach ($categories as $cat) {
            if ($cat['parent'] === null) {
                $model = Category::updateOrCreate(
                    ['name' => $cat['name']],
                    [
                        'description' => $cat['description'],
                        'parent_id'   => null,
                        'is_active'   => true,
                        'sort_order'  => 0,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]
                );
                $byName[$cat['name']] = $model->id;
            }
        }

        // Create subs
        foreach ($categories as $cat) {
            if ($cat['parent'] !== null) {
                $parentId = $byName[$cat['parent']] ?? Category::where('name', $cat['parent'])->value('id');
                $model = Category::updateOrCreate(
                    ['name' => $cat['name']],
                    [
                        'description' => $cat['description'],
                        'parent_id'   => $parentId,
                        'is_active'   => true,
                        'sort_order'  => 0,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]
                );
                $byName[$cat['name']] = $model->id;
            }
        }
    }
}
