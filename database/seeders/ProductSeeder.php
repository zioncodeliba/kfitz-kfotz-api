<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use App\Models\Product;
use App\Models\Category;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Map category NAME -> 4 items (name, sku, price, sale, image query)
        $catalog = [
            'Electronics' => [
                ['4K Smart TV 55"', 'ELEC-TV-55-001', 2499.00, 2199.00, '4k,tv'],
                ['Bluetooth Speaker Pro', 'ELEC-SPK-PR-002', 399.00, null, 'bluetooth,speaker'],
                ['Wi-Fi 6 Router', 'ELEC-WIFI6-003', 549.00, 499.00, 'wifi,router'],
                ['Smart Home Hub', 'ELEC-HUB-004', 329.00, null, 'smart,home'],
            ],
            'Clothing' => [
                ['Classic Cotton T-Shirt', 'CLOT-TEE-001', 79.00, 59.00, 'tshirt,clothing'],
                ['Slim Fit Jeans', 'CLOT-JEANS-002', 199.00, null, 'jeans,denim'],
                ['Lightweight Hoodie', 'CLOT-HOOD-003', 169.00, 149.00, 'hoodie,fashion'],
                ['Chino Pants', 'CLOT-CHINO-004', 179.00, null, 'chino,pants'],
            ],
            // Subs of Electronics
            'Smartphones' => [
                ['Galaxy S24', 'PHN-GS24-001', 3599.00, 3299.00, 'smartphone,android'],
                ['iPhone 15 Pro', 'PHN-IP15P-002', 4699.00, null, 'iphone,smartphone'],
                ['Pixel 9', 'PHN-PX9-003', 3299.00, 3099.00, 'pixel,smartphone'],
                ['OnePlus 13', 'PHN-OP13-004', 2899.00, null, 'oneplus,phone'],
            ],
            'Laptops' => [
                ['Ultrabook 14"', 'LAP-ULTRA14-001', 3590.00, 3390.00, 'laptop,ultrabook'],
                ['Gaming Laptop 16"', 'LAP-GAME16-002', 6290.00, null, 'gaming,laptop'],
                ['Student Laptop 15.6"', 'LAP-STUD15-003', 2190.00, 1990.00, 'student,laptop'],
                ['Creator Laptop 14"', 'LAP-CRT14-004', 4890.00, null, 'creator,laptop'],
            ],
            'Cameras' => [
                ['Mirrorless Camera X10', 'CAM-MLX10-001', 2890.00, 2590.00, 'camera,mirrorless'],
                ['Action Cam Pro', 'CAM-ACTP-002', 1390.00, null, 'action,camera'],
                ['Compact Camera Z', 'CAM-CMZ-003', 990.00, 890.00, 'compact,camera'],
                ['Prime Lens 50mm', 'CAM-LEN50-004', 690.00, null, '50mm,lens'],
            ],
            'Headphones' => [
                ['Wireless ANC Headphones', 'AUD-ANC-001', 599.00, 549.00, 'headphones,anc'],
                ['True Wireless Earbuds', 'AUD-TWS-002', 349.00, null, 'earbuds,wireless'],
                ['Studio Headphones', 'AUD-STU-003', 429.00, 399.00, 'studio,headphones'],
                ['Gaming Headset', 'AUD-GAME-004', 379.00, null, 'gaming,headset'],
            ],
            // Subs of Clothing
            'Men' => [
                ['Men\'s Oxford Shirt', 'MEN-OXF-001', 149.00, 129.00, 'mens,shirt'],
                ['Men\'s Running Shorts', 'MEN-SHRT-002', 99.00, null, 'mens,shorts'],
                ['Men\'s Leather Belt', 'MEN-BELT-003', 89.00, 79.00, 'leather,belt'],
                ['Men\'s Winter Coat', 'MEN-COAT-004', 399.00, null, 'mens,coat'],
            ],
            'Women' => [
                ['Women\'s Blouse', 'WMN-BLS-001', 139.00, 119.00, 'womens,blouse'],
                ['Women\'s Midi Dress', 'WMN-DRS-002', 229.00, null, 'womens,dress'],
                ['Women\'s Leggings', 'WMN-LEGG-003', 109.00, 89.00, 'women,leggings'],
                ['Women\'s Cardigan', 'WMN-CARD-004', 169.00, null, 'womens,cardigan'],
            ],
            'Kids' => [
                ['Kids Graphic Tee', 'KID-TEE-001', 59.00, 49.00, 'kids,tshirt'],
                ['Kids Sneakers', 'KID-SNK-002', 149.00, null, 'kids,sneakers'],
                ['Kids Hoodie', 'KID-HOOD-003', 129.00, 109.00, 'kids,hoodie'],
                ['Kids Pajama Set', 'KID-PJM-004', 99.00, null, 'kids,pajamas'],
            ],
            'Shoes' => [
                ['Running Shoes', 'SHO-RUN-001', 299.00, 269.00, 'running,shoes'],
                ['Leather Boots', 'SHO-BOOT-002', 379.00, null, 'leather,boots'],
                ['Casual Sneakers', 'SHO-SNK-003', 269.00, 239.00, 'casual,sneakers'],
                ['Sandals', 'SHO-SAND-004', 149.00, null, 'sandals,footwear'],
            ],
            // Root: Home & Kitchen
            'Home & Kitchen' => [
                ['Non-stick Pan 28cm', 'HK-PAN28-001', 129.00, 109.00, 'kitchen,pan'],
                ['Air Fryer 4L', 'HK-AIRF-002', 399.00, 369.00, 'air,fryer'],
                ['Memory Foam Pillow', 'HK-PIL-003', 159.00, null, 'pillow,bedroom'],
                ['Chef Knife 8"', 'HK-KNIFE8-004', 179.00, 149.00, 'chef,knife'],
            ],
            // Subs of Home & Kitchen
            'Furniture' => [
                ['Modern Sofa 3-Seat', 'FUR-SOFA3-001', 2590.00, 2390.00, 'sofa,livingroom'],
                ['Dining Table Set', 'FUR-DINE-002', 1990.00, null, 'dining,table'],
                ['Office Chair Ergonomic', 'FUR-OFFCHR-003', 499.00, 449.00, 'office,chair'],
                ['Bookshelf 5-Tier', 'FUR-SHELF5-004', 349.00, null, 'bookshelf,furniture'],
            ],
            'Cookware' => [
                ['Stainless Pot 5L', 'CKW-POT5-001', 199.00, 179.00, 'stainless,pot'],
                ['Cast Iron Skillet', 'CKW-SKLT-002', 229.00, null, 'cast,iron,skillet'],
                ['Knife Set 5pcs', 'CKW-KNSET-003', 259.00, 229.00, 'knife,set'],
                ['Glass Food Containers', 'CKW-CONT-004', 149.00, null, 'glass,containers'],
            ],
            'Home Decor' => [
                ['Area Rug 160x230', 'DEC-RUG-001', 399.00, 349.00, 'area,rug'],
                ['Wall Art Set', 'DEC-ART3-002', 229.00, null, 'wall,art'],
                ['Table Lamp', 'DEC-LAMP-003', 159.00, 139.00, 'table,lamp'],
                ['Throw Pillow Cover', 'DEC-PILC-004', 49.00, null, 'throw,pillow'],
            ],
            'Appliances' => [
                ['Robot Vacuum', 'APP-ROBV-001', 999.00, 899.00, 'robot,vacuum'],
                ['Espresso Machine', 'APP-ESP-002', 1290.00, null, 'espresso,machine'],
                ['Microwave 28L', 'APP-MW28-003', 449.00, 399.00, 'microwave,kitchen'],
                ['Air Purifier', 'APP-AIRP-004', 690.00, null, 'air,purifier'],
            ],
            // Root: Sports & Outdoors
            'Sports & Outdoors' => [
                ['Yoga Mat Pro', 'SPRT-YOGA-001', 129.00, 109.00, 'yoga,mat'],
                ['Hiking Backpack 30L', 'SPRT-BPK30-002', 279.00, null, 'hiking,backpack'],
                ['Stainless Water Bottle', 'SPRT-BOT-003', 89.00, 69.00, 'water,bottle'],
                ['Trekking Poles', 'SPRT-TRK-004', 199.00, null, 'trekking,poles'],
            ],
            // Subs of Sports & Outdoors
            'Fitness' => [
                ['Adjustable Dumbbells 20kg', 'FIT-DB20-001', 449.00, 399.00, 'dumbbells,fitness'],
                ['Resistance Bands Set', 'FIT-RB-002', 99.00, null, 'resistance,bands'],
                ['Kettlebell 12kg', 'FIT-KB12-003', 179.00, 159.00, 'kettlebell,12kg'],
                ['Foam Roller', 'FIT-FR-004', 89.00, null, 'foam,roller'],
            ],
            'Camping' => [
                ['2-Person Tent', 'CAMP-TENT2-001', 429.00, 389.00, 'tent,camping'],
                ['Sleeping Bag 0°C', 'CAMP-SLP0-002', 269.00, null, 'sleepingbag,camping'],
                ['Camping Stove', 'CAMP-STOVE-003', 179.00, 159.00, 'camping,stove'],
                ['Headlamp 300lm', 'CAMP-HL-004', 119.00, null, 'headlamp,camping'],
            ],
            'Cycling' => [
                ['Hybrid Bike M', 'CYC-HYB-M-001', 1790.00, 1690.00, 'hybrid,bicycle'],
                ['Cycling Helmet', 'CYC-HELM-002', 279.00, null, 'cycling,helmet'],
                ['Bike Lock U-Lock', 'CYC-LOCK-003', 149.00, 129.00, 'bike,lock'],
                ['LED Bike Lights', 'CYC-LITE-004', 119.00, null, 'bike,lights'],
            ],
            'Team Sports' => [
                ['Football Size 5', 'TEAM-FB5-001', 139.00, 119.00, 'football,soccer,ball'],
                ['Basketball Indoor/Outdoor', 'TEAM-BB-002', 149.00, null, 'basketball,ball'],
                ['Shin Guards', 'TEAM-SHIN-003', 89.00, 69.00, 'soccer,shinguards'],
                ['Goalkeeper Gloves', 'TEAM-GKGL-004', 179.00, null, 'goalkeeper,gloves'],
            ],
        ];

        $lowStockOverrides = [
            'ELEC-TV-55-001'   => ['stock_quantity' => 5, 'min_stock_alert' => 12],
            'CLOT-JEANS-002'   => ['stock_quantity' => 3, 'min_stock_alert' => 10],
            'PHN-IP15P-002'    => ['stock_quantity' => 4, 'min_stock_alert' => 9],
            'HK-AIRF-002'      => ['stock_quantity' => 2, 'min_stock_alert' => 8],
            'FUR-SOFA3-001'    => ['stock_quantity' => 1, 'min_stock_alert' => 5],
            'FIT-RB-002'       => ['stock_quantity' => 6, 'min_stock_alert' => 12],
            'LAP-GAME16-002'   => ['stock_quantity' => 4, 'min_stock_alert' => 9],
            'CAMP-TENT2-001'   => ['stock_quantity' => 3, 'min_stock_alert' => 7],
        ];

        foreach ($catalog as $categoryName => $items) {
            $categoryId = Category::where('name', $categoryName)->value('id');
            if (!$categoryId) {
                $this->command->warn("Category '{$categoryName}' not found. Skipping.");
                continue;
            }

            foreach ($items as [$name, $sku, $price, $sale, $imgQuery]) {
                $img = $this->productImageUrl($sku, $imgQuery);
                $stockQuantity = $sale ? 60 : 100;
                $minStockAlert = 8;

                if (isset($lowStockOverrides[$sku])) {
                    $stockQuantity = $lowStockOverrides[$sku]['stock_quantity'];
                    $minStockAlert = $lowStockOverrides[$sku]['min_stock_alert'];
                }

                Product::updateOrCreate(
                    ['sku' => $sku],
                    [
                        'name'            => $name,
                        'description'     => $this->shortDescription($name, $categoryName),
                        'price'           => $price,
                        'sale_price'      => $sale,
                        'stock_quantity'  => $stockQuantity,
                        'min_stock_alert' => $minStockAlert,
                        'category_id'     => $categoryId,
                        'is_active'       => true,
                        'is_featured'     => in_array($categoryName, ['Electronics','Smartphones','Laptops','Appliances']) ? 1 : 0,
                        'images'          => [$img],     // JSON cast on Product model
                        'variations'      => $this->variationsFor($categoryName),
                        'weight'          => null,
                        'dimensions'      => null,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]
                );
            }
        }
    }

    private function productImageUrl(string $sku, ?string $imgQuery): string
    {
        $label = trim(str_replace(',', ' ', (string) $imgQuery));
        if ($label === '') {
            $label = $sku;
        }

        if (strlen($label) > 32) {
            $label = substr($label, 0, 29) . '...';
        }

        $label = strtoupper($label);
        $encodedLabel = rawurlencode($label);

        $palettes = [
            ['1d3557', 'f1faee'],
            ['457b9d', 'f1faee'],
            ['2a9d8f', 'ffffff'],
            ['f4a261', '1b263b'],
            ['e76f51', 'ffffff'],
            ['4a4e69', 'f1faee'],
            ['2d6a4f', 'ffffff'],
        ];

        $index = abs(crc32($sku)) % count($palettes);
        [$background, $foreground] = $palettes[$index];

        return "https://dummyimage.com/800x800/{$background}/{$foreground}&text={$encodedLabel}";
    }

    private function shortDescription(string $name, string $categoryName): string
    {
        return match ($categoryName) {
            'Electronics','Smartphones','Laptops','Cameras','Headphones'
                => "High quality {$name} for modern tech needs.",
            'Clothing','Men','Women','Kids','Shoes'
                => "Comfortable, durable, and stylish {$name}.",
            'Home & Kitchen','Furniture','Cookware','Home Decor','Appliances'
                => "{$name} for a better home experience.",
            'Sports & Outdoors','Fitness','Camping','Cycling','Team Sports'
                => "{$name} built for performance outdoors.",
            default => $name,
        };
    }

    private function variationsFor(string $categoryName): array
    {
        return match ($categoryName) {
            'Smartphones' => ['storage' => ['128GB','256GB','512GB'], 'color' => ['Black','Blue','Natural']],
            'Laptops'     => ['ram' => ['8GB','16GB','32GB'], 'storage' => ['256GB','512GB','1TB']],
            'Headphones'  => ['color' => ['Black','White','Blue']],
            'Shoes'       => ['size' => [38,39,40,41,42,43,44], 'color' => ['Black','White','Gray']],
            'Clothing','Men','Women','Kids' => ['size' => ['XS','S','M','L','XL']],
            'Furniture'   => ['color' => ['Oak','Walnut','Black']],
            'Cookware'    => ['pieces' => [1,3,5,8]],
            'Home Decor'  => ['color' => ['Beige','Gray','Blue']],
            'Appliances'  => ['voltage' => ['220V']],
            'Fitness'     => ['weight' => ['Light','Medium','Heavy']],
            'Camping'     => ['capacity' => ['1P','2P','3P']],
            'Cycling'     => ['size' => ['S','M','L']],
            'Team Sports' => ['size' => ['S','M','L']],
            default       => [],
        };
    }
}
