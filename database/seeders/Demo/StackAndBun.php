<?php

namespace Database\Seeders\Demo;

/**
 * "Stack & Bun" — a smash-burger joint in Lahore (two branches). Same
 * shape as SpiceRoute::data(). Prices are PKR, exclusive of 16% Punjab
 * sales tax.
 */
class StackAndBun
{
    public static function data(): array
    {
        return [
            'restaurant' => [
                'slug' => 'stack-and-bun',
                'name' => 'Stack & Bun',
                'legal_name' => 'Stack & Bun Hospitality (Pvt.) Ltd.',
                'description' => "Smashed-to-order beef burgers, crispy chicken and thick shakes. Lahore's late-night stack since 2019.",
                'phone' => '+924211178226',
                'email' => 'hello@stackandbun.demo',
                'website' => null,
                'address' => 'MM Alam Road, Gulberg III, Lahore',
                'city' => 'Lahore',
                'country' => 'Pakistan',
                'currency' => 'PKR',
                'timezone' => 'Asia/Karachi',
                'status' => 'ACTIVE',
                'theme' => ['primaryColor' => '#181818', 'secondaryColor' => '#FFC107'],
            ],
            // The restaurant's own customer app: demo key + branding.
            'customer_app' => [
                'key' => 'rk_demo_stackandbun',
                'branding' => [
                    'app_name' => 'Stack & Bun',
                    'tagline' => 'Smashed to order',
                    'colors' => [
                        'primary' => '#FFB800', 'secondary' => '#181818', 'accent' => '#E53935',
                        'background' => '#FFFFFF', 'surface' => '#F4F4F4', 'text' => '#151515',
                    ],
                    'theme_mode' => 'light',
                    'font' => 'Montserrat',
                    'corner_radius' => 'pill',
                    'logo_url' => 'logos/stack-and-bun.png',
                    'icon_url' => 'logos/stack-and-bun.png',
                    'banner_urls' => ['stack-and-bun/double-smash.jpg', 'stack-and-bun/crispy-zinger.jpg', 'stack-and-bun/chocolate-fudge-shake.jpg'],
                    'welcome_title' => 'Stack it up',
                    'welcome_subtitle' => 'Smash burgers, crispy chicken and thick shakes — late.',
                    'contact' => ['phone' => '+924211178226'],
                    'social' => ['instagram' => 'https://instagram.com/stackandbun.demo'],
                    'android_package_id' => 'com.stackandbun.customer',
                    'ios_bundle_id' => 'com.stackandbun.customer',
                ],
            ],
            'logo' => 'logos/stack-and-bun.png',
            'image_folder' => 'stack-and-bun',
            'email_domain' => 'stackandbun.demo',
            'style' => 'burger',

            'settings' => [
                'order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
                'min_order_amount' => 600,
                'default_prep_time_minutes' => 15,
                'tax_enabled' => true,
                'tax_percentage' => 16,
                'cash_tax_percentage' => 16,
                'card_tax_percentage' => 5,
                'fbr_number' => 'POS-LHR-0071538',
                'kitchen_order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
                'tax_inclusive' => false,
                'delivery_enabled' => true,
                'delivery_fee' => 199,
                'free_delivery_threshold' => 2500,
                'order_number_scheme' => 'BRANCH',
                'order_number_daily_reset' => false,
                'branch_selection_mode' => 'BOTH',
            ],

            'mix' => ['DINE_IN' => 40, 'TAKEAWAY' => 20, 'DELIVERY' => 40],
            'orders_per_day' => ['weekday' => [16, 24], 'weekend' => [26, 36]],

            'restaurant_staff' => [
                'restaurant-owner' => [['Ali Hassan Butt', '+923004417283']],
                'restaurant-admin' => [['Ayesha Malik', '+923214098561']],
            ],

            'branches' => [
                'GLB' => [
                    'name' => 'Gulberg III — MM Alam Road',
                    'phone' => '+924235761190',
                    'email' => 'gulberg@stackandbun.demo',
                    'address' => '84-C/1, MM Alam Road, Gulberg III',
                    'city' => 'Lahore',
                    'state' => 'Punjab',
                    'country' => 'Pakistan',
                    'postal_code' => '54660',
                    'latitude' => 31.5104,
                    'longitude' => 74.3440,
                    'priority' => 10,
                    'settings' => ['prep_time_minutes' => 15, 'max_delivery_distance_km' => 9],
                    'hours' => ['open' => '12:00', 'close' => '02:00', 'weekend_close' => '03:00'],
                    'zones' => [
                        ['name' => 'Gulberg, Model Town & Shadman — standard', 'radius_km' => 6, 'fee' => null],
                        ['name' => 'Extended (6–9 km)', 'radius_km' => 9, 'fee' => 299],
                    ],
                    'tables' => [
                        ['section' => 'Ground Floor', 'prefix' => 'G', 'count' => 8, 'capacity' => [2, 2, 4, 4, 4, 4, 6, 2]],
                        ['section' => 'Upper Deck', 'prefix' => 'U', 'count' => 6, 'capacity' => [4, 4, 4, 6, 2, 2]],
                    ],
                    'areas' => [
                        'Main Boulevard, Gulberg III', 'MM Alam Road, Gulberg III', 'Block E, Gulberg II',
                        'Block C, Gulberg III', 'Liberty Market, Gulberg III', 'Jail Road, Shadman',
                        'Block H, Gulberg III', 'Garden Town',
                    ],
                    'staff' => [
                        'branch-manager' => [['Omer Farooq', '+923334178520']],
                        'cashier' => [['Zainab Rauf', '+923458203716']],
                        'kitchen' => [['Asad Javed', '+923017739402'], ['Saqib Nawaz', '+923124058613']],
                        'waiter' => [['Talha Aslam', '+923361927054'], ['Fahad Chaudhry', '+923214850392'], ['Areeb Khalid', '+923056691827']],
                        'rider' => [['Nadeem Abbas', '+923437120586'], ['Rizwan Haider', '+923170286493']],
                    ],
                ],
                'DHA' => [
                    'name' => 'DHA Phase 5',
                    'phone' => '+924237189044',
                    'email' => 'dha@stackandbun.demo',
                    'address' => 'Plot 118, Block CCA, DHA Phase 5',
                    'city' => 'Lahore',
                    'state' => 'Punjab',
                    'country' => 'Pakistan',
                    'postal_code' => '54792',
                    'latitude' => 31.4636,
                    'longitude' => 74.4085,
                    'priority' => 20,
                    'settings' => ['prep_time_minutes' => 15, 'max_delivery_distance_km' => 10],
                    'hours' => ['open' => '12:00', 'close' => '01:00', 'weekend_close' => '02:30'],
                    'zones' => [
                        ['name' => 'DHA Phases 1–6 — standard', 'radius_km' => 7, 'fee' => null],
                        ['name' => 'Extended (7–10 km)', 'radius_km' => 10, 'fee' => 299],
                    ],
                    'tables' => [
                        ['section' => 'Indoor', 'prefix' => 'T', 'count' => 8, 'capacity' => [2, 4, 4, 4, 6, 2, 4, 4]],
                        ['section' => 'Patio', 'prefix' => 'P', 'count' => 4, 'capacity' => [4, 4, 6, 2]],
                    ],
                    'areas' => [
                        'Block C, DHA Phase 5', 'Block D, DHA Phase 5', 'Block CCA, DHA Phase 5',
                        'Block H, DHA Phase 5', 'Block Y, DHA Phase 3', 'Block K, DHA Phase 6',
                        'Block E, DHA Phase 4', 'Block L, DHA Phase 5',
                    ],
                    'staff' => [
                        'branch-manager' => [['Hassan Raza', '+923008812645']],
                        'cashier' => [['Maham Tariq', '+923235047189']],
                        'kitchen' => [['Junaid Akram', '+923066215830'], ['Waleed Anjum', '+923144870263']],
                        'waiter' => [['Saad Mughal', '+923339402516'], ['Moiz Ahmed', '+923027315948']],
                        'rider' => [['Shahzad Iqbal', '+923416098327'], ['Kashif Mehmood', '+923158460271']],
                    ],
                ],
            ],

            'modifier_groups' => [
                'patties' => [
                    'name' => 'Patties', 'type' => 'SINGLE', 'required' => false, 'min' => 0, 'max' => 1,
                    'modifiers' => [['Make it Double', 450, false], ['Make it Triple', 850, false]],
                ],
                'cheese' => [
                    'name' => 'Cheese', 'type' => 'MULTIPLE', 'required' => false, 'min' => 0, 'max' => 2,
                    'modifiers' => [['Extra American Cheese', 120, false], ['Cheddar Slice', 150, false], ['Jalapeño Cheese Sauce', 180, false]],
                ],
                'burger_addons' => [
                    'name' => 'Add-ons', 'type' => 'MULTIPLE', 'required' => false, 'min' => 0, 'max' => 4,
                    'modifiers' => [['Smoked Beef Strips', 250, false], ['Fried Egg', 120, false], ['Caramelised Onions', 100, false], ['Sautéed Mushrooms', 150, false], ['Pickled Jalapeños', 80, false]],
                ],
                'meal' => [
                    'name' => 'Make it a Meal', 'type' => 'SINGLE', 'required' => false, 'min' => 0, 'max' => 1,
                    'modifiers' => [['Fries & Soft Drink', 490, false], ['Loaded Fries & Soft Drink', 890, false]],
                ],
                'heat' => [
                    'name' => 'Heat Level', 'type' => 'SINGLE', 'required' => true, 'min' => 1, 'max' => 1,
                    'modifiers' => [['Mild', 0, false], ['Hot', 0, true], ['Extra Hot', 0, false]],
                ],
                'dip' => [
                    'name' => 'Dip', 'type' => 'SINGLE', 'required' => true, 'min' => 1, 'max' => 1,
                    'modifiers' => [['Garlic Mayo', 0, true], ['Honey Mustard', 0, false], ['Smoky BBQ', 0, false], ['Stack Sauce', 0, false]],
                ],
                'fries_size' => [
                    'name' => 'Fries Size', 'type' => 'SINGLE', 'required' => true, 'min' => 1, 'max' => 1,
                    'modifiers' => [['Regular', 0, true], ['Large', 200, false]],
                ],
                'soft_drink' => [
                    'name' => 'Soft Drink Flavour', 'type' => 'SINGLE', 'required' => true, 'min' => 1, 'max' => 1,
                    'modifiers' => [['Cola', 0, true], ['Lemon-Lime', 0, false], ['Orange', 0, false], ['Diet Cola', 0, false]],
                ],
            ],

            'categories' => [
                [
                    'name' => 'Smash Burgers', 'image' => 'double-smash',
                    'description' => '100% beef, smashed thin on a screaming-hot griddle for crispy edges. Served on toasted potato buns.',
                    'products' => [
                        ['classic-smash', 'Classic Smash', 1090, 'Smashed beef patty, American cheese, pickles, onions and Stack sauce.', 12, ['patties', 'cheese', 'burger_addons', 'meal'], 'main', 10],
                        ['double-smash', 'Double Smash', 1490, 'Two smashed patties, double American cheese, pickles, onions and Stack sauce.', 12, ['cheese', 'burger_addons', 'meal'], 'main', 9],
                        ['mushroom-swiss', 'Mushroom Swiss', 1590, 'Smashed patty, sautéed mushrooms, Swiss cheese and garlic aioli.', 14, ['patties', 'cheese', 'burger_addons', 'meal'], 'main', 5],
                        ['jalapeno-heat', 'Jalapeño Heat', 1550, 'Smashed patty, pepper jack, pickled jalapeños and chipotle mayo.', 12, ['patties', 'cheese', 'burger_addons', 'meal'], 'main', 5],
                        ['smoky-bbq-stack', 'Smoky BBQ Stack', 1690, 'Double patty, smoked beef strips, cheddar, crispy onions and smoky BBQ sauce.', 14, ['cheese', 'burger_addons', 'meal'], 'main', 6],
                    ],
                ],
                [
                    'name' => 'Chicken', 'image' => 'crispy-zinger',
                    'description' => 'Buttermilk-brined, double-dredged, fried crisp — or flame-grilled.',
                    'products' => [
                        ['crispy-zinger', 'Crispy Chicken Burger', 990, 'Crunchy fried chicken thigh, lettuce and spicy mayo.', 12, ['cheese', 'burger_addons', 'meal'], 'main', 10],
                        ['nashville-hot', 'Nashville Hot Chicken', 1190, 'Fried chicken dunked in cayenne-chilli oil, pickles and slaw.', 12, ['heat', 'cheese', 'meal'], 'main', 6],
                        ['grilled-chicken', 'Grilled Chicken Burger', 1090, 'Flame-grilled chicken breast, lettuce, tomato and honey mustard.', 14, ['cheese', 'burger_addons', 'meal'], 'main', 4],
                    ],
                ],
                [
                    'name' => 'Wings & Tenders', 'image' => 'buffalo-wings',
                    'description' => 'For sharing. Or not.',
                    'products' => [
                        ['buffalo-wings', 'Buffalo Wings (8 pcs)', 1150, 'Crispy wings tossed in tangy buffalo sauce, with ranch.', 15, ['heat'], 'share', 8],
                        ['honey-garlic-wings', 'Honey Garlic Wings (8 pcs)', 1190, 'Sticky honey, garlic and soy glaze with sesame and spring onion.', 15, [], 'share', 6],
                        ['chicken-tenders', 'Chicken Tenders (5 pcs)', 1090, 'Hand-breaded chicken strips with your choice of dip.', 12, ['dip'], 'share', 7],
                    ],
                ],
                [
                    'name' => 'Sides', 'image' => 'loaded-fries',
                    'description' => 'Fries done right, and friends.',
                    'products' => [
                        ['classic-fries', 'Classic Fries', 390, 'Skin-on fries, twice-cooked, seasoned with sea salt.', 6, ['fries_size'], 'side', 10],
                        ['loaded-fries', 'Loaded Fries', 890, 'Fries under cheese sauce, beef bits, jalapeños and Stack sauce.', 8, [], 'side', 7],
                        ['onion-rings', 'Onion Rings', 590, 'Thick-cut, beer-free batter, served with smoky mayo.', 7, [], 'side', 4],
                        ['mozzarella-sticks', 'Mozzarella Sticks (6 pcs)', 890, 'Golden breaded mozzarella with marinara.', 7, [], 'side', 4],
                        ['coleslaw', 'Coleslaw', 250, 'Crunchy cabbage and carrot slaw.', 2, [], 'side', 2],
                    ],
                ],
                [
                    'name' => 'Shakes', 'image' => 'chocolate-fudge-shake',
                    'description' => 'Thick, hand-spun shakes with real ice cream.',
                    'products' => [
                        ['chocolate-fudge-shake', 'Chocolate Fudge Shake', 850, 'Chocolate ice cream, fudge sauce, whipped cream and brownie bits.', 5, [], 'shake', 7],
                        ['cookies-cream-shake', 'Cookies & Cream Shake', 850, 'Vanilla ice cream blended with chocolate sandwich cookies.', 5, [], 'shake', 8],
                        ['strawberry-shake', 'Strawberry Shake', 790, 'Strawberry ice cream and fresh strawberries.', 5, [], 'shake', 4],
                        ['salted-caramel-shake', 'Salted Caramel Shake', 890, 'Vanilla ice cream, salted caramel and whipped cream.', 5, [], 'shake', 5],
                    ],
                ],
                [
                    'name' => 'Drinks', 'image' => 'mint-lemonade',
                    'description' => 'Ice-cold.',
                    'products' => [
                        ['soft-drink', 'Soft Drink (500 ml)', 250, 'Chilled bottle.', 1, ['soft_drink'], 'drink', 10],
                        ['mint-lemonade', 'Mint Lemonade', 450, 'Fresh lemon, mint and a little sugar, over ice.', 3, [], 'drink', 6],
                        ['peach-iced-tea', 'Peach Iced Tea', 450, 'Brewed black tea with peach and lemon.', 3, [], 'drink', 4],
                    ],
                ],
                [
                    'name' => 'Deals', 'image' => 'family-feast',
                    'description' => 'Better together.',
                    'products' => [
                        ['duo-deal', 'Duo Deal', 2990, '2 Classic Smash, 2 regular fries and 2 soft drinks.', 14, ['soft_drink'], 'deal', 6],
                        ['family-feast', 'Family Feast', 5490, '2 Classic Smash, 2 Crispy Chicken, 2 large fries, 8 buffalo wings and a 1.5 L soft drink.', 18, [], 'deal', 3],
                    ],
                ],
            ],

            'branch_products' => [
                ['DHA', 'smoky-bbq-stack', true, 1790],
                ['GLB', 'strawberry-shake', false, null],
            ],

            'coupons' => [
                ['code' => 'SMASH20', 'type' => 'PERCENTAGE', 'value' => 20, 'min' => 1500, 'max_discount' => 400, 'limit' => null, 'per_customer' => 1, 'from' => -90, 'until' => 180, 'active' => true],
                ['code' => 'FRIDAYFEAST', 'type' => 'FIXED', 'value' => 700, 'min' => 5000, 'max_discount' => null, 'limit' => 300, 'per_customer' => 4, 'from' => -40, 'until' => 50, 'active' => true],
                ['code' => 'BIGBITE10', 'type' => 'PERCENTAGE', 'value' => 10, 'min' => 1000, 'max_discount' => 250, 'limit' => null, 'per_customer' => null, 'from' => -60, 'until' => 30, 'active' => true],
                ['code' => 'LATENIGHT', 'type' => 'FIXED', 'value' => 300, 'min' => 2000, 'max_discount' => null, 'limit' => 200, 'per_customer' => 2, 'from' => -30, 'until' => 90, 'active' => false],
            ],

            'customers' => [
                'Usama Rehman', 'Hira Anwar', 'Bilal Aslam', 'Mahnoor Iftikhar', 'Hamza Sajid', 'Laiba Khurram',
                'Abdullah Nadeem', 'Eman Fatima', 'Shahrukh Javed', 'Areesha Tahir', 'Daniyal Sheikh', 'Rida Asghar',
                'Muneeb Arshad', 'Noor ul Ain', 'Haris Mahmood', 'Aiman Zafar', 'Zain Ul Abideen', 'Momina Rafiq',
                'Faizan Akbar', 'Sana Waheed', 'Rayyan Qamar', 'Kinza Imtiaz', 'Ahmad Bilal', 'Minahil Saeed',
                'Waleed Pervaiz', 'Hafsa Naeem', 'Talal Chaudhry', 'Zoya Kamran', 'Shayan Rasheed', 'Anaya Munir',
                'Ibrahim Tanveer', 'Maira Sohail', 'Arham Latif', 'Esha Shafiq', 'Musa Hayat', 'Fiza Nasir',
            ],
        ];
    }
}
