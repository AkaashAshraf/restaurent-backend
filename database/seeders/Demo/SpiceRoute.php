<?php

namespace Database\Seeders\Demo;

/**
 * "Spice Route" — a family-style desi restaurant in Karachi (two
 * branches). Pure data: RestaurantBuilder turns this into rows and
 * OrderHistory uses the `tag`/`weight` of each product to build believable
 * orders. Prices are PKR, exclusive of 13% Sindh sales tax.
 */
class SpiceRoute
{
    public static function data(): array
    {
        return [
            'restaurant' => [
                'slug' => 'spice-route',
                'name' => 'Spice Route',
                'legal_name' => 'Spice Route Foods (Pvt.) Ltd.',
                'description' => "Karachi's family-style desi kitchen — charcoal BBQ, karahi cooked to order and dum biryani, since 2014.",
                'phone' => '+922111177242',
                'email' => 'hello@spiceroute.demo',
                'website' => null,
                'address' => 'Bukhari Commercial, DHA Phase 6, Karachi',
                'city' => 'Karachi',
                'country' => 'Pakistan',
                'currency' => 'PKR',
                'timezone' => 'Asia/Karachi',
                'status' => 'ACTIVE',
                'theme' => ['primaryColor' => '#7A1C24', 'secondaryColor' => '#F2A93B'],
            ],
            'logo' => 'logos/spice-route.png',
            'image_folder' => 'spice-route',
            'email_domain' => 'spiceroute.demo',
            'style' => 'desi',

            'settings' => [
                'order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
                'min_order_amount' => 800,
                'default_prep_time_minutes' => 25,
                'tax_enabled' => true,
                'tax_percentage' => 13,
                'cash_tax_percentage' => 13,
                'card_tax_percentage' => 8,
                'fbr_number' => 'POS-KHI-0048291',
                'kitchen_order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
                'tax_inclusive' => false,
                'delivery_enabled' => true,
                'delivery_fee' => 150,
                'free_delivery_threshold' => 3000,
                'order_number_scheme' => 'BRANCH',
                'order_number_daily_reset' => false,
                'branch_selection_mode' => 'BOTH',
            ],

            // Order-type mix and daily volume per branch for the history.
            'mix' => ['DINE_IN' => 55, 'TAKEAWAY' => 15, 'DELIVERY' => 30],
            'orders_per_day' => ['weekday' => [14, 20], 'weekend' => [22, 30]],

            'restaurant_staff' => [
                'restaurant-owner' => [['Faisal Qureshi', '+923008234117']],
                'restaurant-admin' => [['Sana Mirza', '+923212678450']],
            ],

            'branches' => [
                'DHA' => [
                    'name' => 'DHA Phase 6',
                    'phone' => '+922135241180',
                    'email' => 'dha@spiceroute.demo',
                    'address' => 'Plot 12-C, Lane 4, Bukhari Commercial, DHA Phase 6',
                    'city' => 'Karachi',
                    'state' => 'Sindh',
                    'country' => 'Pakistan',
                    'postal_code' => '75500',
                    'latitude' => 24.7937,
                    'longitude' => 67.0664,
                    'priority' => 10,
                    'settings' => ['prep_time_minutes' => 25, 'max_delivery_distance_km' => 8],
                    'hours' => ['open' => '12:00', 'close' => '00:30', 'weekend_close' => '01:30'],
                    'zones' => [
                        ['name' => 'DHA & Clifton — standard', 'radius_km' => 5, 'fee' => null],
                        ['name' => 'Extended (5–8 km)', 'radius_km' => 8, 'fee' => 250],
                    ],
                    'tables' => [
                        ['section' => 'Family Hall', 'prefix' => 'F', 'count' => 10, 'capacity' => [4, 4, 6, 6, 4, 4, 6, 8, 4, 6]],
                        ['section' => 'Rooftop', 'prefix' => 'R', 'count' => 6, 'capacity' => [4]],
                        ['section' => 'Private Dining', 'prefix' => 'P', 'count' => 2, 'capacity' => [10, 12]],
                    ],
                    'areas' => [
                        'Khayaban-e-Bukhari, DHA Phase 6', 'Khayaban-e-Rahat, DHA Phase 6',
                        'Khayaban-e-Shahbaz, DHA Phase 6', 'Khayaban-e-Hilal, DHA Phase 6',
                        'Nishat Commercial, DHA Phase 6', 'Badar Commercial, DHA Phase 5',
                        'Khayaban-e-Seher, DHA Phase 7', 'Ittehad Commercial, DHA Phase 6',
                    ],
                    'staff' => [
                        'branch-manager' => [['Kamran Siddiqui', '+923332104598']],
                        'cashier' => [['Hira Baig', '+923452871306']],
                        'kitchen' => [['Rafiq Ahmed', '+923002451879'], ['Shahid Iqbal', '+923151967420']],
                        'waiter' => [['Bilal Khan', '+923112640385'], ['Usman Tariq', '+923363095274'], ['Naveed Akhtar', '+923022718463']],
                        'rider' => [['Imran Ali', '+923432560918'], ['Zubair Hussain', '+923172984501']],
                    ],
                ],
                'CLF' => [
                    'name' => 'Clifton Block 5',
                    'phone' => '+922135877420',
                    'email' => 'clifton@spiceroute.demo',
                    'address' => 'Plot G-7, Block 5, Clifton',
                    'city' => 'Karachi',
                    'state' => 'Sindh',
                    'country' => 'Pakistan',
                    'postal_code' => '75600',
                    'latitude' => 24.8139,
                    'longitude' => 67.0306,
                    'priority' => 20,
                    'settings' => ['prep_time_minutes' => 25, 'max_delivery_distance_km' => 7],
                    'hours' => ['open' => '12:30', 'close' => '00:00', 'weekend_close' => '01:00'],
                    'zones' => [
                        ['name' => 'Clifton & Saddar — standard', 'radius_km' => 5, 'fee' => null],
                        ['name' => 'Extended (5–7 km)', 'radius_km' => 7, 'fee' => 250],
                    ],
                    'tables' => [
                        ['section' => 'Main Hall', 'prefix' => 'T', 'count' => 8, 'capacity' => [4, 4, 6, 4, 2, 2, 6, 8]],
                        ['section' => 'Sea View Terrace', 'prefix' => 'S', 'count' => 6, 'capacity' => [4, 4, 2, 2, 6, 4]],
                    ],
                    'areas' => [
                        'Block 5, Clifton', 'Block 4, Clifton', 'Block 2, Clifton', 'Block 9, Clifton',
                        'Boat Basin, Block 5, Clifton', 'Zamzama, DHA Phase 5', 'Teen Talwar, Block 5, Clifton',
                        'Khayaban-e-Roomi, Block 3, Clifton',
                    ],
                    'staff' => [
                        'branch-manager' => [['Adnan Sheikh', '+923218846107']],
                        'cashier' => [['Mehwish Anwar', '+923041378852']],
                        'kitchen' => [['Tariq Mehmood', '+923347015963'], ['Arif Hussain', '+923086342719']],
                        'waiter' => [['Hamza Ali', '+923129473608'], ['Danish Raza', '+923456120837']],
                        'rider' => [['Sajid Mehmood', '+923315869240'], ['Waqas Ahmed', '+923016704382']],
                    ],
                ],
            ],

            'modifier_groups' => [
                'chicken_portion' => [
                    'name' => 'Portion — Chicken', 'type' => 'SINGLE', 'required' => true, 'min' => 1, 'max' => 1,
                    'modifiers' => [['Half (serves 2)', 0, true], ['Full (serves 4)', 1400, false]],
                ],
                'mutton_portion' => [
                    'name' => 'Portion — Mutton', 'type' => 'SINGLE', 'required' => true, 'min' => 1, 'max' => 1,
                    'modifiers' => [['Half (serves 2)', 0, true], ['Full (serves 4)', 2800, false]],
                ],
                'spice' => [
                    'name' => 'Spice Level', 'type' => 'SINGLE', 'required' => false, 'min' => 0, 'max' => 1,
                    'modifiers' => [['Mild', 0, false], ['Medium', 0, true], ['Desi Hot', 0, false]],
                ],
                'karahi_extras' => [
                    'name' => 'Karahi Extras', 'type' => 'MULTIPLE', 'required' => false, 'min' => 0, 'max' => 3,
                    'modifiers' => [['Desi Ghee Tarka', 250, false], ['Extra Makhan', 150, false], ['Extra Ginger & Green Chillies', 0, false]],
                ],
                'nihari_toppings' => [
                    'name' => 'Nihari Toppings', 'type' => 'MULTIPLE', 'required' => false, 'min' => 0, 'max' => 3,
                    'modifiers' => [['Nalli', 450, false], ['Maghaz', 600, false], ['Extra Tarka', 150, false]],
                ],
                'biryani_extras' => [
                    'name' => 'Biryani Extras', 'type' => 'MULTIPLE', 'required' => false, 'min' => 0, 'max' => 3,
                    'modifiers' => [['Raita', 120, false], ['Kachumber Salad', 150, false], ['Extra Boti', 300, false]],
                ],
                'bbq_sides' => [
                    'name' => 'BBQ Sides', 'type' => 'MULTIPLE', 'required' => false, 'min' => 0, 'max' => 3,
                    'modifiers' => [['Extra Mint Chutney', 60, false], ['Onion & Lemon Salad', 80, false], ['Garlic Mayo', 80, false]],
                ],
                'lime_soda' => [
                    'name' => 'Lime Soda Style', 'type' => 'SINGLE', 'required' => true, 'min' => 1, 'max' => 1,
                    'modifiers' => [['Sweet', 0, true], ['Salted', 0, false], ['Sweet & Salted', 0, false]],
                ],
                'soft_drink' => [
                    'name' => 'Soft Drink Flavour', 'type' => 'SINGLE', 'required' => true, 'min' => 1, 'max' => 1,
                    'modifiers' => [['Cola', 0, true], ['Lemon-Lime', 0, false], ['Orange', 0, false], ['Diet Cola', 0, false]],
                ],
                'chai' => [
                    'name' => 'Chai Sweetness', 'type' => 'SINGLE', 'required' => false, 'min' => 0, 'max' => 1,
                    'modifiers' => [['Normal Sugar', 0, true], ['Less Sugar', 0, false], ['No Sugar', 0, false]],
                ],
            ],

            // tag: what kind of item it is (drives order building).
            // weight: how popular it is within its tag.
            'categories' => [
                [
                    'name' => 'Starters', 'image' => 'chicken-samosa',
                    'description' => 'Small plates to start — crisp, tangy and made fresh.',
                    'products' => [
                        ['chicken-samosa', 'Chicken Samosa (2 pcs)', 450, 'Hand-folded pastry filled with spiced chicken qeema, served with imli chutney.', 10, [], 'starter', 5],
                        ['vegetable-pakora', 'Vegetable Pakora', 550, 'Onion, potato and spinach fritters in gram-flour batter, with mint raita.', 10, [], 'starter', 4],
                        ['chicken-corn-soup', 'Chicken Corn Soup', 650, 'Silky soup with shredded chicken, sweetcorn and egg ribbons. Chilli vinegar on the side.', 8, [], 'starter', 3],
                        ['chana-chaat', 'Chana Chaat', 490, 'Chickpeas, potato, onion and tomato tossed with tamarind and chaat masala.', 7, [], 'starter', 3],
                    ],
                ],
                [
                    'name' => 'BBQ & Tandoor', 'image' => 'seekh-kabab',
                    'description' => 'Straight off the charcoal — marinated overnight, grilled to order.',
                    'products' => [
                        ['chicken-tikka', 'Chicken Tikka (Leg Quarter)', 850, 'Leg quarter in red chilli, yoghurt and lemon marinade, charred over coal.', 20, ['spice', 'bbq_sides'], 'bbq', 8],
                        ['seekh-kabab', 'Beef Seekh Kabab (4 pcs)', 1250, 'Hand-minced beef with green chilli, coriander and roasted spices on skewers.', 18, ['spice', 'bbq_sides'], 'bbq', 7],
                        ['malai-boti', 'Chicken Malai Boti', 1450, 'Boneless chicken in cream, cheese and white pepper — soft and smoky.', 18, ['bbq_sides'], 'bbq', 7],
                        ['tandoori-chicken', 'Tandoori Chicken (Half)', 1350, 'Half bird in classic tandoori masala, roasted in the clay oven.', 25, ['spice', 'bbq_sides'], 'bbq', 4],
                        ['mutton-chops', 'Mutton Chops (4 pcs)', 2650, 'Tender mutton chops in raw papaya and garam masala marinade.', 25, ['spice', 'bbq_sides'], 'bbq', 3],
                        ['fish-tikka', 'Fish Tikka', 1850, 'Boneless sole in ajwain and lemon masala, grilled on skewers.', 18, ['spice', 'bbq_sides'], 'bbq', 3],
                    ],
                ],
                [
                    'name' => 'Karahi & Handi', 'image' => 'chicken-karahi',
                    'description' => 'Cooked fresh in the wok with tomatoes, ginger and green chillies. Half serves 2, full serves 4.',
                    'products' => [
                        ['chicken-karahi', 'Chicken Karahi', 1650, 'Bone-in chicken cooked down with tomatoes, ginger, green chillies and black pepper.', 25, ['chicken_portion', 'spice', 'karahi_extras'], 'curry', 10],
                        ['mutton-karahi', 'Mutton Karahi', 2950, 'Tender mutton in a rich tomato and desi ghee masala, finished with fresh ginger.', 35, ['mutton_portion', 'spice', 'karahi_extras'], 'curry', 6],
                        ['chicken-handi', 'Chicken Handi', 1750, 'Boneless chicken in a creamy yoghurt and cashew gravy, slow-cooked in a clay handi.', 25, ['chicken_portion', 'spice'], 'curry', 6],
                        ['beef-nihari', 'Beef Nihari', 1150, 'Overnight slow-cooked beef shank stew, with ginger, green chilli, fried onion and lemon.', 10, ['spice', 'nihari_toppings'], 'curry', 5],
                    ],
                ],
                [
                    'name' => 'Curries', 'image' => 'butter-chicken',
                    'description' => 'Home-style curries and daal.',
                    'products' => [
                        ['butter-chicken', 'Butter Chicken', 1650, 'Tandoori chicken in a velvety tomato, butter and fenugreek gravy.', 20, ['spice'], 'curry', 5],
                        ['tadka-daal', 'Daal Tadka', 750, 'Yellow lentils tempered with cumin, garlic and dried red chillies.', 12, ['spice'], 'curry', 4],
                        ['palak-paneer', 'Palak Paneer', 1150, 'Fresh spinach puree with soft cottage cheese cubes and a touch of cream.', 15, ['spice'], 'curry', 3],
                    ],
                ],
                [
                    'name' => 'Biryani & Rice', 'image' => 'chicken-biryani',
                    'description' => 'Dum-cooked Karachi-style biryani with aloo and plenty of masala.',
                    'products' => [
                        ['chicken-biryani', 'Chicken Biryani', 750, 'Sella rice layered with spicy chicken masala, potatoes, mint and fried onion.', 10, ['biryani_extras'], 'rice', 10],
                        ['mutton-biryani', 'Mutton Biryani', 1150, 'Slow-dum biryani with tender mutton, saffron and whole spices.', 12, ['biryani_extras'], 'rice', 5],
                        ['family-biryani-deg', 'Family Biryani Deg (serves 4)', 2950, 'A small deg of chicken biryani for the table, with raita and salad.', 15, [], 'rice', 2],
                    ],
                ],
                [
                    'name' => 'Breads', 'image' => 'garlic-naan',
                    'description' => 'Fresh from the tandoor.',
                    'products' => [
                        ['tandoori-roti', 'Tandoori Roti', 60, 'Whole-wheat roti baked on the tandoor wall.', 4, [], 'bread', 10],
                        ['plain-naan', 'Plain Naan', 90, 'Soft leavened naan, brushed with butter.', 4, [], 'bread', 8],
                        ['garlic-naan', 'Garlic Naan', 180, 'Naan with garlic, coriander and butter.', 5, [], 'bread', 6],
                        ['kalonji-naan', 'Kalonji Naan', 130, 'Naan topped with nigella and sesame seeds.', 5, [], 'bread', 3],
                        ['paratha', 'Lachha Paratha', 150, 'Flaky, layered whole-wheat paratha.', 5, [], 'bread', 4],
                    ],
                ],
                [
                    'name' => 'Desserts', 'image' => 'kheer',
                    'description' => 'Something sweet to finish.',
                    'products' => [
                        ['kheer', 'Kheer', 450, 'Slow-cooked rice pudding with cardamom, almonds and pistachio, served chilled.', 3, [], 'dessert', 5],
                        ['gulab-jamun', 'Gulab Jamun (2 pcs)', 350, 'Warm milk dumplings soaked in rose and cardamom syrup.', 3, [], 'dessert', 6],
                        ['gajar-halwa', 'Gajar Halwa', 550, 'Carrots slow-cooked in milk and desi ghee with khoya and nuts. Winter favourite.', 5, [], 'dessert', 3],
                        ['pistachio-kulfi', 'Pista Kulfi', 400, 'Dense, creamy frozen milk dessert with pistachio.', 2, [], 'dessert', 4],
                    ],
                ],
                [
                    'name' => 'Drinks', 'image' => 'mint-margarita',
                    'description' => 'Fresh coolers, lassi and doodh patti.',
                    'products' => [
                        ['mint-margarita', 'Mint Margarita', 450, 'Frozen lemon and fresh mint cooler — no alcohol.', 5, [], 'drink', 8],
                        ['mango-lassi', 'Mango Lassi', 550, 'Sindhri mango blended with yoghurt and a pinch of cardamom.', 5, [], 'drink', 5],
                        ['fresh-lime-soda', 'Fresh Lime Soda', 350, 'Fresh lime with soda, the way you like it.', 3, ['lime_soda'], 'drink', 6],
                        ['soft-drink', 'Soft Drink (345 ml)', 180, 'Chilled can.', 1, ['soft_drink'], 'drink', 10],
                        ['mineral-water', 'Mineral Water (1.5 L)', 150, 'Chilled bottled water.', 1, [], 'drink', 7],
                        ['doodh-patti-chai', 'Doodh Patti Chai', 180, 'Strong tea brewed in milk, served in a kulhad.', 5, ['chai'], 'drink', 6],
                    ],
                ],
            ],

            // Per-branch menu differences.
            'branch_products' => [
                ['CLF', 'fish-tikka', true, 1950],
                ['CLF', 'mutton-chops', false, null],
                ['DHA', 'gajar-halwa', false, null],
            ],

            'coupons' => [
                ['code' => 'WELCOME15', 'type' => 'PERCENTAGE', 'value' => 15, 'min' => 1500, 'max_discount' => 500, 'limit' => null, 'per_customer' => 1, 'from' => -120, 'until' => 240, 'active' => true],
                ['code' => 'FAMILY500', 'type' => 'FIXED', 'value' => 500, 'min' => 5000, 'max_discount' => null, 'limit' => 500, 'per_customer' => 3, 'from' => -60, 'until' => 60, 'active' => true],
                ['code' => 'BIRYANI10', 'type' => 'PERCENTAGE', 'value' => 10, 'min' => 1200, 'max_discount' => 300, 'limit' => null, 'per_customer' => null, 'from' => -45, 'until' => 45, 'active' => true],
                ['code' => 'AZADI14', 'type' => 'PERCENTAGE', 'value' => 14, 'min' => 2000, 'max_discount' => 700, 'limit' => 1000, 'per_customer' => 1, 'from' => -61, 'until' => -47, 'active' => false],
            ],

            'customers' => [
                'Ahsan Jamil', 'Mariam Siddiqui', 'Owais Khan', 'Fatima Zaidi', 'Hassan Ali Shah', 'Rabia Ahmed',
                'Saad Hussain', 'Nida Farooqui', 'Zeeshan Memon', 'Amna Rizvi', 'Taimur Baig', 'Sadia Kazmi',
                'Farhan Qureshi', 'Iqra Naqvi', 'Shehryar Malik', 'Hina Abbasi', 'Junaid Lakhani', 'Maryam Jafri',
                'Asad Ullah', 'Kiran Shaikh', 'Mustafa Chinoy', 'Anum Sheikh', 'Rizwan Dawood', 'Sidra Hashmi',
                'Yasir Soomro', 'Samina Pirzada', 'Omar Paracha', 'Ayesha Lodhi', 'Noman Ghani', 'Zara Moosa',
                'Arsalan Kamal', 'Beenish Akhtar', 'Umair Vohra', 'Mahnoor Qazi', 'Salman Merchant', 'Aliza Haroon',
            ],
        ];
    }
}
