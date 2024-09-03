<?php

namespace App\Services\List;

use App\Models\Product;
use App\Packages\Card\Card;
use App\Packages\Product\ProductLazyCollection;
use WordpressThemeCore\Base\Cache\GlobalCache;
use WC_DateTime;
use App\Services\List\ListClient;
use App\Services\CustomerCard;
use WP_Query;
use App\Packages\VipPrice\VipProduct;
use App\Packages\VipPrice\VipCustomer;

use function WordpressThemeCore\collect;

final class ListModel
{
    private $id;
    private $items = [];
    private $products = [];
    private $store;
    private $data = [
        'name' => '',
        'surname' => '',
        'start_date' => null,
    ];

    private $cache = [];
    private $queried_card_number;

    /**
     * Gets babylists from Toshiba
     * @param array $payload
     */
    public static function getLists($payload)
    {
        try {
            $api = ListClient::getInstance();
            $data = $api->query($payload);

            if (!$data->isSuccessful() && !$data->getResponse()) {
                // service error
                wc_get_logger()->warning(__METHOD__ . ' Babylist service error', [
                    'data' => $data,
                    'payload' => $payload,
                ]);

                throw new \Exception(__('Babylist service error', 'prenatal'));
            }

            if (!$data->isSuccessful() && $data->getErrorCode() == 5) {
                return collect($data->data['lists'])->map(function ($list) {
                    $list['is_closed'] = true;
                    return $list;
                })->toArray();
            }
            if ($data->isSuccessful()) {
                return $data->data['lists'];
            }
            return;
        } catch (\Exception $exp) {
            wc_get_logger()->warning(__METHOD__ . ' List not found', [
                'error' => $exp,
                'payload' => $payload,
            ]);

            throw new \Exception(__('Babylist service error', 'prenatal'));
        }
    }

    public static function orderOptions()
    {
        return [
            'id' => __('Ordinamento predefinito', 'prenatal'),
            'price_lowest' => __('Prezzo: dal più economico', 'prenatal'),
            'price_highest' => __('Prezzo: dal più caro', 'prenatal')
        ];
    }

    public static function priceRanges(): array
    {
        $ranges = [0, 50, 100, 150];

        $price_ranges = collect($ranges)->map(function ($range, $key) use ($ranges) {
            return [
                'min' => $range,
                'max' => $range != 150 ? $ranges[$key + 1] : null,
                'name' => $range . ($range != 150 ? '-' . $ranges[$key + 1] : ' +'),
                'slug' => $range . ($range != 150 ? '-' . $ranges[$key + 1] : ''),
                'count' => 0
            ];
        })->toArray();

        return $price_ranges;
    }

    public static function filters(): array
    {
        $available_filters = [
            'must_have',
            'disponibili',
            'regalati',
            'categoria',
            'price',
            'order_by'
        ];
        $filters = $_GET;

        foreach ($filters as $key => $param) {
            if (!in_array($key, $available_filters)) {
                unset($filters[$key]);
            }
        }

        return $filters;
    }

    /**
     * Checks if a list is blacklisted
     * @param $id
     */
    public static function isBlacklisted($id)
    {
        $blacklisted_ids = get_option('babylist_blacklists', []);
        return in_array($id, $blacklisted_ids);
    }
    /**
     * Gets data from Toshiba babylist
     * @param array $data
     */
    public function __construct($data, $queried_card_number = null)
    {
        $items = $data['items'] ?? [];

        $this->id = $data['list_code'];
        $this->items = $items;
        $this->products = $items ?
            collect($items)
            ->keyBy('detail_id')
            ->toArray() : [];
        $this->data = [
            'first_name' => $data['mother']['name'],
            'last_name'  => $data['mother']['surname'],
            'email'      => $data['email'] ?? '',
            'second_parent_name' => trim("{$data['father']['name']} {$data['father']['surname']}"),
            'start_date' => $this->formatDateField($data['open_date']),
            'end_date' => $this->formatDateField(isset($data['is_closed']) && isset($data['close_date']) ? $data['close_date'] : $data['expiration_date']),
            'donation_total_amount' => $data['donation_total_amount'],
            'card_number' => $data['fidelity_code'],
            'is_closed' => $data['is_closed'] ?? false,
            'sbs' => $data['sbs'],
            'is_vip' => $this->isVip($data['fidelity_code']) || VipCustomer::isVip(get_current_user_id())
        ];
        $this->store = $this->getShopIdFromLocateId($data['sbs']);
        $this->queried_card_number = $queried_card_number;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function products(): array
    {
        return $this->products;
    }

    public function items(): array
    {
        return $this->items;
    }

    public function product($sku): ?array
    {
        return collect($this->products)->where('alpha_code', (int) $sku)->first() ?? null;
    }

    protected function isVip($cardNumber): bool
    {
        return Card::isVip((string) $cardNumber);
    }

    public function productByDetailId(int $productId): ?array
    {
        return $this->products[$productId] ?? null;
    }

    public function pageId()
    {
        return GlobalCache::version('1.0.0')
            ->time(DAY_IN_SECONDS)
            ->get("list_page_id", function () {
                global $wpdb;
                $id = (int) $wpdb->get_var("SELECT post_id FROM wp_postmeta WHERE meta_key = '_wp_page_template' AND meta_value = 'pages/list.php' LIMIT 1");

                if (!$id) {
                    return false;
                }

                return $id;
            });
    }

    public function permalink()
    {
        return add_query_arg("list-id", $this->id(), get_permalink($this->pageId()));
    }

    public function whatsappShortUrl(): ?string
    {
        $whatsappUrl = add_query_arg([
            'utm_source'   => urlencode('whatsapp'),
            'utm_medium'   => urlencode('social'),
            'utm_campaign' => urlencode('share'),
        ], $this->permalink());

        return \App\Packages\Firebase\DynamicLinks::generate($whatsappUrl);
    }

    public function listName(): string
    {
        return mb_convert_encoding(trim("{$this->prop('first_name')} {$this->prop('last_name')}"), "ISO-8859-1", "UTF-8");
    }

    public function fullName(): string
    {
        if ($this->listName()) {
            return sprintf(__("%s " . ($this->prop('second_parent_name') ? "e %s" : ""), "prenatal"), $this->listName(), $this->prop('second_parent_name'));
        }
        return "";
    }

    public function startDate(): ?WC_DateTime
    {
        return $this->prop("start_date");
    }

    public function endDate(): ?WC_DateTime
    {
        return $this->prop("end_date");
    }

    /**
     * Checks if list has must have products
     */
    public function hasMustHave(): bool
    {
        return collect($this->products)->whereIn('importance', [1, 2, 3])->isNotEmpty();
    }

    /**
     * Checks if the list product is must have
     * @param $id
     */
    public function isMustHave($id): bool
    {
        $must_have = collect($this->products)->where('product_id', '=', $id)->whereIn('importance', [1, 2, 3])->first();
        if (!$must_have) {
            return false;
        }
        return $must_have;
    }

    public function isEmpty(): bool
    {
        return empty($this->products);
    }

    /**
     * Returns formated babylist data
     */
    public function toArray()
    {
        $total_items      = $this->getItems();
        $gifted_items     = $this->getGiftedItems();
        $filtered_gifted_items = $this->getFilteredGiftedItems();
        $available_items  = $this->getAvailableItems();
        $total_amount     = $this->getAmount($total_items);
        $gifted_amount    = $this->getAmount($filtered_gifted_items);
        $available_amount = $this->getAmount($available_items);

        return [
            'details' => [
                'image'                 => null,
                'title'                 => __('Lista nascita', 'prenatal'),
                'store'                 => $this->store ? $this->store->post_title : null,
                'list_name'             => $this->listName(),
                'first_name'            => mb_convert_encoding(trim("{$this->prop('first_name')}"), "ISO-8859-1", "UTF-8"),
                'last_name'             => mb_convert_encoding(trim("{$this->prop('last_name')}"), "ISO-8859-1", "UTF-8"),
                'card_number'           => $this->prop('card_number'),
                'donation_total_amount' => $this->prop('donation_total_amount') ? format_price($this->prop('donation_total_amount')) : '',
                'name'                  => $this->fullName(),
                'id'                    => $this->id(),
                'created_date'          => $this->startDate() ? $this->startDate()->format('d/m/Y') : '',
                'close_date'            => $this->endDate() ? $this->endDate()->format('d/m/Y') : '',
                'is_closed'             => $this->isClosed(),
                'days_left'             => $this->daysLeft(),
                'isEmpty'               => $this->isEmpty(),
                'link'                  => $this->permalink(),
                'whatsappShortUrl'      => $this->whatsappShortUrl(),
                'has_must_have'         => $this->hasMustHave(),
                'isListUser'            => $this->isListUser(),
            ],
            'stats' => [
                [
                    'percentage' => $this->getPercentage(count($this->products), count($gifted_items)),
                    'details'    => [
                        [
                            'label'    => 'ARTICOLI',
                            'sublabel' => 'IN LISTA',
                            'value'    => count($this->products),
                        ],
                        [
                            'label'    => 'ARTICOLI',
                            'sublabel' => 'REGALATI',
                            'value'    => count($gifted_items),
                        ],
                    ],
                ],
                [
                    'percentage' => $this->getPercentage($total_amount, $gifted_amount),
                    'details'    => [
                        [
                            'label'    => 'IMPORTO',
                            'sublabel' => 'LISTA',
                            'value'    => format_price($total_amount),
                        ],
                        [
                            'label'    => 'IMPORTO',
                            'sublabel' => 'REGALI',
                            'value'    => format_price($gifted_amount),
                        ],
                    ],
                ],
                [
                    'details' => [
                        [
                            'label'       => 'Ti mancano €500 per ottenere il buono del 5% sul valore della spesa',
                            'is_disabled' => true,
                            'is_hidden'   => $gifted_amount <> 0,
                        ],
                        [
                            'label'       => 'Ti mancano €1000 per ottenere il buono del 10% sul valore della spesa',
                            'is_disabled' => true,
                            'is_hidden'   => $gifted_amount <> 0,
                        ],
                        [
                            'label'       => 'Ti mancano € ' . ((float) 500 - $gifted_amount) . ' per ottenere il buono del 5% sul valore della spesa',
                            'is_disabled' => $this->calculateSconto() < 5,
                            'is_hidden'   => $this->calculateSconto() < 5 && $gifted_amount <> 0 ? false : true,
                        ],
                        [
                            'label'       => 'Hai ottenuto un buono del valore di ' . $this->getDiscount($gifted_amount, 5) . ' (pari al 5% del totale spesa)',
                            'is_disabled' => $this->calculateSconto() <> 5,
                            'is_hidden'   => $this->calculateSconto() < 5 || $this->calculateSconto() == 10,
                        ],
                        [
                            'label'       => 'Ti mancano € ' . ((float) 1000 - $gifted_amount) . ' per ottenere il buono del 10% sul valore della spesa',
                            'is_disabled' => $this->calculateSconto() < 10,
                            'is_hidden'   => $this->calculateSconto() < 10 && $gifted_amount <> 0 ? false : true,
                        ],
                        [
                            'label'       => 'Hai ottenuto un buono del valore di ' . $this->getDiscount($gifted_amount, 10) . ' (pari al 10% del totale spesa)',
                            'is_disabled' => $this->calculateSconto() <> 10,
                            'is_hidden'   => $this->calculateSconto() <> 10,
                        ],
                    ],
                ],
            ],
            'guest_details' => [
                [
                    'label' => 'ARTICOLI DISPONIBILI',
                    'value' => count($this->products) - count($gifted_items),
                ],
            ],
            'products'          => $this->paginateProducts(),
            'product_count'     => count($this->products),
            'categories'        => $this->getCategories(),
            'filters'           => static::filters(),
            'order_by'          => isset($_GET['order_by']) ? $_GET['order_by'] : '',
            'price_ranges'      => $this->getPriceRanges(),
            'gifted_products'   => [
                'products' => $gifted_items,
                'count'    => count($gifted_items),
                'amount'   => format_price($gifted_amount),
            ],
            'available_products' => [
                'products' => $available_items,
                'count'    => count($available_items),
                'amount'   => format_price($available_amount),
            ],
            'coupon' => $this->getCoupon(),
            'sconto' => number_format((($gifted_amount * $this->calculateSconto()) / 100), 2)
        ];
    }

    private function getCoupon()
    {
        if (!$this->isClosed() || !$this->showAdminInfo()) {
            return;
        }

        $user = get_user_by('email', $this->prop('email'));

        if (!$user) {
            return;
        }

        $controller = CustomerCard\CustomerCardHelper::getInstance();
        $coupons    = $controller->getCustomerCoupons($user->ID);

        if ($coupons) {
            return $coupons[0];
        }

        return;
    }

    private function calculateSconto()
    {
        $amount = $this->getAmount($this->getGiftedItems());

        if ($amount >= 500 && $amount <= 999) {
            $sconto = 5;
        };

        if ($amount >= 1000) {
            $sconto = 10;
        };

        return $sconto ?? 0;
    }

    private function getDiscount($price, $sconto)
    {
        return format_price(($price * $sconto) / 100);
    }

    private function getAmount($items)
    {
        return (float) collect($items)->sum(function ($item) {
            return (float) $this->getItemPrice($item['alpha_code'], $item['unit_price'], $item['mandatory'] ?? 0, $item['gifted']);
        });
    }

    private function getPercentage($total, $gifted)
    {
        if ($gifted == 0 || $total == 0) {
            return 0;
        }

        return round(($gifted / $total) * 100);
    }

    private function formatDateField($value): ?WC_DateTime
    {
        if ($value && is_string($value)) {
            return (new WC_DateTime(wc_string_to_datetime($value)));
        }

        if ($value instanceof WC_DateTime) {
            return $value;
        }

        return null;
    }

    private function prop($key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function getPriceRanges(): array
    {
        $price_ranges = static::priceRanges();
        $products = $this->applyStatusFilters($this->getItems());

        if ($products && count($products) > 0) {
            foreach ($products as $product) {
                $price = $product['unit_price'];

                foreach ($price_ranges as $key => $range) {
                    $range['max'] <> null ?
                        (($price >= $range['min'] && $price < $range['max']) ? $price_ranges[$key]['count']++ : '')
                        : ($price >= 150 ? $price_ranges[$key]['count']++ : '');
                }
            }
        }

        return $price_ranges;
    }

    public function getCategories(): array
    {
        if (isset($this->cache['categories'])) {
            return $this->cache['categories'];
        }

        $categories = collect();

        $products = collect($this->getItems())
            ->map(function ($item) {
                if ($item['wc_product'] ?? false) {
                    $wc_product = wc_get_product($item['id']);
                    return $wc_product;
                }

                return null;
            })
            ->filter();

        foreach ($products as $product) {
            $categories_added = [];
            $terms = wp_get_post_terms(($product->is_type('variation') ? $product->get_parent_id() : $product->get_id()), 'product_cat');

            foreach ($terms as $category) {
                if ($category->parent != 0) {
                    $ancestors = get_ancestors($category->term_id, 'product_cat');
                    $category = get_term(end($ancestors));
                }
                if (!$categories->contains('id', $category->term_id)) {
                    $categories->push([
                        'id' => $category->term_id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'link' => get_category_link($category->term_id),
                        'count' => 1
                    ]);
                    array_push($categories_added, $category->term_id);
                } else {
                    if (!in_array($category->term_id, $categories_added)) {
                        $cats = $categories->toArray();
                        foreach ($cats as $key => $cat) {
                            if ($cat['id'] == $category->term_id) {
                                $cats[$key]['count']++;
                            }
                        }
                        $categories = collect($cats);
                        array_push($categories_added, $category->term_id);
                    }
                }
            }
        }

        $this->cache['categories'] = $categories ? $categories->toArray() : [];

        return $this->cache['categories'];
    }

    private function getAvailableItems()
    {
        return collect($this->getItems())->where('gifted', false)->values()->toArray();
    }

    private function getGiftedItems()
    {
        return collect($this->getItems())->where('gifted', true)->values()->toArray();
    }

    private function getFilteredGiftedItems()
    {
        return collect($this->getGiftedItems())->where('participate', true)->values()->toArray();
    }

    public function isReservedItem($sku, $detailId, $available_qty)
    {
        return collect($this->getListOrderedItems())->where('sku', $sku)->where('detail_id', $detailId)->first() && $available_qty != 0;
    }

    private function getListOrderedItems()
    {
        global $wpdb;

        $time = (new WC_DateTime())->setTimezone(new \DateTimeZone(wp_timezone_string()))->modify("-3 days")->format("Y-m-d H:i:s");

        $query = "SELECT sku,detail_id
                    FROM {$wpdb->prefix}babylist_orders
                    INNER JOIN {$wpdb->prefix}posts
                    ON {$wpdb->prefix}posts.ID = {$wpdb->prefix}babylist_orders.order_id
                    WHERE babylist_id=%s
                    AND {$wpdb->prefix}posts.post_date>%s";

        $data = $wpdb->get_results($wpdb->prepare($query, (string) $this->id, $time), ARRAY_A);

        return $data ?? [];
    }

    public function getItemPrice($sku, $unit_price, $mandatory, $gifted, $hasFirstOverridingCoupon = false)
    {
        if ((int) $mandatory == 1 || $gifted) {
            return $unit_price;
        }
        $wc_product_id = wc_get_product_id_by_sku($sku);
        if (!$wc_product_id) {
            return $unit_price;
        }
        $product = wc_get_product($wc_product_id);

        if ($hasFirstOverridingCoupon) {
            return $product->get_regular_price();
        }

        // check if user is vip
        if ($this->data['is_vip'] && VipProduct::hasVipPrice($product)) {
            return $this->vipPrice($product);
        }

        return $product->get_price();
    }

    /**
     * @return float
     */
    private function vipPrice($product)
    {
        if ($product instanceof \WC_Product_Variable) {
            return VipProduct::variationPrice($product);
        }

        return VipProduct::price($product);
    }

    private function getItems()
    {
        if (isset($this->cache['items'])) {
            return $this->cache['items'];
        }

        $skus = collect($this->items ?: [])
            ->pluck('alpha_code')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $query = $skus ? new WP_Query([
            'post_type' => ['product', 'product_variation'],
            'posts_per_page' => count($skus),
            'post_status' => ['private', 'publish'],
            'meta_query' => [
                [
                    'key'     => '_sku',
                    'value'   => $skus,
                    'compare' => 'IN',
                ]
            ]
        ]) : null;

        $products = ProductLazyCollection::make(
            array_filter(array_map('wc_get_product', $query ? $query->posts : []))
        )
            ->toCardArray([
                'hasHover' => !\App\Packages\WooCommerce\PosChecker::hasPosId(),
                'hasIdeal' => false,
                'context' => [
                    'name' => 'babylist',
                ],
            ]);

        Product::reset();

        $products = collect($products)->keyBy('sku');

        $recommandation_products = collect(
            \WordpressServicesUi\ProductRecommendation\ProductRecommendationApi::new()
                ->getProducts(
                    new \WordpressServicesUi\ProductRecommendation\Request\GetProductsRequest($skus)
                )->getResult()
        )->keyBy('sku');

        $this->cache['items'] = $this->items ? collect($this->items)
            ->map(function ($item) use ($products, $recommandation_products) {
                if ($product_item = $products->get($item['alpha_code'])) {
                    $product_item['importance'] = $item['importance'];
                    $product_item['detail_id'] = $item['detail_id'];
                    $product_item['available_qty'] = $item['available_qty'];
                    $product_item['wc_product'] = true;
                    $product_item['is_babylist_product'] = true;
                    $price = (float) $this->getItemPrice($item['alpha_code'], $item['unit_price'], $item['mandatory'] ?? 0, $item['available_qty'] == 0);
                    $product_item['price'] = format_price($price * $item['qty']);
                    $product_item['site_price'] = $price * $item['qty'];
                    $product_item['discounts'] = $product_item['labels'] = $product_item['badges'] = [];
                    $product_item['vip_price'] = $product_item['previous_price'] = $product_item['kit'] = '';
                    $product_item['name'] = str_replace('privato: ', '', strtolower($product_item['name']));
                    $product_item['has_stock'] = $item['available_qty'] != 0;
                    $product_item['alpha_code'] = $item['alpha_code'];
                    $product_item['hide_disponibility'] = true;
                    $product_item['mandatory'] = $item['mandatory'] ?? 0;
                } else {
                    $product_item = $item;
                    $product_item['price'] = format_price($item['unit_price'] * $item['qty']);
                    $product_item['site_price'] = $item['unit_price'] * $item['qty'];

                    if ($recommandation_product = $recommandation_products->get($item['alpha_code'])) {
                        $product_item['descr'] = $recommandation_product->full_title;
                        $product_item['brand'] = $recommandation_product->brand;
                        $product_item['category'] = $this->mapCategory($recommandation_product->category);
                    }
                }

                $product_item['quantity'] = (int) ($item['qty'] ?? '');
                $product_item['gifted'] = $item['available_qty'] == 0 ? true : false;
                $product_item['reserved'] = static::isReservedItem($item['alpha_code'], $item['detail_id'], $item['available_qty']);
                $product_item['gifted_qty'] = $item['qty'] - $item['available_qty'];
                $product_item['unit_price'] = $item['unit_price'] * $item['qty'];
                $product_item['unit_price'] = $product_item['gifted'] ? $item['unit_price'] * $item['qty'] : $product_item['site_price']; // phpcs:ignore
                $product_item['participate'] = $item['participate'] == 1 ? true : false;

                return $product_item;
            })
            ->toArray() : [];

        return $this->cache['items'];
    }

    public function getItemBySku($sku)
    {
        return collect($this->items)->where('alpha_code', $sku)->first();
    }

    public function getItem($sku, $detailId)
    {
        return collect($this->items)->where('alpha_code', $sku)->where('detail_id', $detailId)->first();
    }

    private function paginateProducts()
    {
        $filters = static::filters();
        $items = collect($this->applyStatusFilters($this->getItems()));

        if (isset($filters['price'])) {
            $range = collect(static::priceRanges())->where('slug', $filters['price'])->first();

            foreach ($items as $key => $product) {
                $price = $product['unit_price'];

                if ($price) {
                    $range['max'] <> null ? ((!($price >= $range['min'] && $price < $range['max'])) ? $items->pull($key) : '')
                        : (!($price >= 150) ? $items->pull($key) : '');
                }
            }
        }

        if (isset($filters['order_by'])) {
            if ($filters['order_by'] == 'price_lowest') {
                $items = $items->sortBy('unit_price');
            } elseif ($filters['order_by'] == 'price_highest') {
                $items = $items->sortByDesc('unit_price');
            }
        }

        $items = $items->values()->toArray();
        $perPage = 20;
        $totalPages = ceil(count($items) / $perPage);
        $currentPage = (int) get_query_var('paged') ?: 1;
        $hasNext = $totalPages > $currentPage;
        $hasPrev = 1 < $currentPage;

        //get page number in account babylist endpoint
        if (is_account_page()) {
            $paged_array = explode('/', get_query_var('list'));
            if (in_array('page', $paged_array)) {
                $currentPage = (int) $paged_array[array_search('page', $paged_array) + 1] ?: $currentPage;
            }
        }

        $data = collect($items)
            ->forPage($currentPage, $perPage)
            ->map(fn ($product) => FormatProductCard::format($product, $this))
            ->toArray();

        return [
            'data' => $data,
            'hasNext' => $hasNext,
            'hasPrev' => $hasPrev,
            'total' => count($items),
            'perPage' => $perPage,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ];
    }

    private function applyStatusFilters($items = null)
    {
        $filters = static::filters();
        $items = collect($items ?? $this->getItems());

        if (isset($filters['must_have'])) {
            $items = $items->whereIn('importance', [1, 2, 3]);
        }

        if (isset($filters['categoria'])) {
            foreach ($items as $key => $product) {
                if ($product['wc_product']) {
                    $wc_product = wc_get_product($product['id']);
                    if (!has_term($filters['categoria'], 'product_cat', ($wc_product->is_type('variation') ? $wc_product->get_parent_id() : $product['id']))) {
                        $items->forget($key);
                    }
                } else {
                    $items->forget($key);
                }
            }
        }

        if (isset($filters['regalati'])) {
            foreach ($items as $key => $product) {
                !$product['gifted'] && !$product['reserved'] ? $items->pull($key) : '';
            }
        }

        if (isset($filters['disponibili'])) {
            foreach ($items as $key => $product) {
                $product['gifted'] || $product['reserved'] ? $items->pull($key) : '';
            }
        }

        return $items;
    }

    public function canBuyFromList()
    {
        return !$this->isClosed() && !$this->isListUser() && !$this->showAdminInfo() && $this->isInStore();
    }

    public function hideAddToCartButton($product)
    {
        $available_qty = (int) $product['available_qty'];
        $sku = (int) $product['alpha_code'];

        if ($this->isReservedItem($sku, $product['detail_id'], $available_qty)) {
            return true;
        }

        $hide = false;

        if (!$this->canBuyFromList() || $available_qty === 0) {
            $hide = true;
        }

        if ((!isset($product['wc_product']) || !$product['wc_product']) && $available_qty === 0) {
            $hide = true;
        }

        if (isset($product['available_online']) && (!$product['available_online'] || !$product['is_visible'])) {
            $hide = true;
        }

        if (isset($product['unit_price']) && $product['unit_price'] <= 0) {
            $hide = true;
        }

        if (isset($product['price']) && $product['price'] <= 0) {
            $hide = true;
        }

        return $hide;
    }

    public function isInStore()
    {
        $store = $this->getShopIdFromLocateId($this->prop('sbs'));
        if (!$store) {
            return null;
        }

        return (int) get_post_meta($store->ID, 'allow_babylist_purchase', true) == 1;
    }

    protected function getShopIdFromLocateId($locate_id)
    {
        $query_args = [
            'post_type'      => 'store',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_key'       => 'store_locate_id',
            'meta_value'     => $locate_id,
        ];

        $stores = get_posts($query_args);
        if (count($stores) > 0) {
            return $stores[0];
        }

        return null;
    }

    protected function isListUser()
    {
        return get_user_meta(get_current_user_id(), 'babylist_code', true) === $this->id;
    }

    public function showAdminInfo()
    {
        $card_number = $this->queried_card_number ?? request()->get('card_number');
        return $card_number == $this->prop('card_number') || $this->isListUser() ? true : false;
    }

    public function isClosed(): bool
    {
        return $this->data['is_closed'];
    }

    public function daysLeft(): ?int
    {
        if (!$this->endDate()) {
            return null;
        }

        $now = new WC_DateTime();
        if ($this->endDate()->getTimestamp() < $now->getTimestamp()) {
            return null;
        }

        $days = $this->endDate()->diff($now)->days;

        if (!$days) {
            return null;
        }

        return $days;
    }

    public function isProductAvailable(int $detail_id, int $quantity)
    {
        $product = $this->productByDetailId($detail_id) ?? null;

        if (!$product) {
            return false;
        }

        return $product['available_qty'] >= $quantity;
    }

    public function hasMinimumAmount(int $detail_id, int $quantity)
    {
        if (!$this->isProductAvailable($detail_id, $quantity)) {
            return;
        }
        return (int) $this->productByDetailId($detail_id)['available_qty'] === $quantity;
    }

    public static function find($id)
    {
        try {
            $list = collect(static::getLists([
                'listCode' => $id,
                'storeId' => substr($id, 0, 4),
            ]))->first();

            if (!$list) {
                return null;
            }

            return new static($list);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function mapCategory($category)
    {
        $mapping = config('list_category_mapping', []);
        $category = strtoupper($category);

        if (isset($mapping[$category])) {
            return $mapping[$category];
        } else {
            return $category;
        }
    }
}
