<?php

namespace Dmdev\MallImport1c\Classes\Search;

use Cms\Classes\Controller;
use OFFLINE\Mall\Models\GeneralSettings;
use OFFLINE\Mall\Models\Product;
use OFFLINE\Mall\Models\Variant;
use OFFLINE\SiteSearch\Classes\Result;
use OFFLINE\SiteSearch\Classes\ResultCollection;

/**
 * Enhances the default Mall search with:
 * — Multi-word AND matching (ignores word order)
 * — е/ё substitution (both directions)
 * — Relevance re-scoring based on match position and completeness
 */
class MallSearchEnhancer
{
    protected string $rawQuery;

    /** @var string[] Normalized tokens */
    protected array $words = [];

    protected Controller $controller;
    protected string $productPage;

    public function __construct(string $rawQuery)
    {
        $this->rawQuery     = trim($rawQuery);
        $this->words        = $this->tokenize($this->normalize($rawQuery));
        $this->controller   = Controller::getController() ?? new Controller();
        $this->productPage  = GeneralSettings::get('product_page', '');
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    public function enhance(ResultCollection $collection): ResultCollection
    {
        // 1. Re-score existing product results based on match quality
        $this->rescoreExistingResults($collection);

        // 2. For multi-word queries: add AND-matched results that original missed
        if (count($this->words) >= 2) {
            $this->addMultiWordResults($collection);
        }

        return $collection->sortByDesc('relevance');
    }

    // -----------------------------------------------------------------------
    // Result enrichment
    // -----------------------------------------------------------------------

    protected function rescoreExistingResults(ResultCollection $collection): void
    {
        foreach ($collection->all() as $result) {
            if (empty($result->meta['is_product']) || !$result->model) {
                continue;
            }
            $result->relevance = $this->score($result->model->name ?? '');
        }
    }

    protected function addMultiWordResults(ResultCollection $collection): void
    {
        // Build a lookup of already-present URLs for O(1) dedup check
        $existingUrls = [];
        foreach ($collection->all() as $result) {
            if ($result->url) {
                $existingUrls[$result->url] = true;
            }
        }

        // --- Products ---
        foreach ($this->findProducts() as $product) {
            $url = $this->buildUrl($product->slug);
            if (!$url || isset($existingUrls[$url])) {
                continue;
            }
            $collection->push($this->makeResult($product, $url));
            $existingUrls[$url] = true;
        }

        // --- Variants ---
        foreach ($this->findVariants() as $variant) {
            if (!$variant->product) {
                continue;
            }
            $url = $this->buildUrl(
                $variant->product->slug,
                $variant->variant_hash_id ?? null
            );
            if (!$url || isset($existingUrls[$url])) {
                continue;
            }
            $collection->push($this->makeResult($variant, $url));
            $existingUrls[$url] = true;
        }
    }

    // -----------------------------------------------------------------------
    // Database queries
    // -----------------------------------------------------------------------

    protected function findProducts()
    {
        $q = Product::published()
            ->where('inventory_management_method', 'single')
            ->with(['brand', 'image_sets.images']);

        foreach ($this->words as $word) {
            $variants = $this->yoVariants($word);
            $q->where(function ($q) use ($variants) {
                foreach ($variants as $v) {
                    $q->orWhere('name', 'like', "%{$v}%")
                      ->orWhere('description_short', 'like', "%{$v}%")
                      ->orWhere('user_defined_id', 'like', "%{$v}%")
                      ->orWhereHas('brand', fn($q) => $q->where('name', 'like', "%{$v}%"));
                }
            });
        }

        return $q->limit(50)->get();
    }

    protected function findVariants()
    {
        $q = Variant::published()
            ->with(['product.brand', 'product.image_sets.images']);

        foreach ($this->words as $word) {
            $variants = $this->yoVariants($word);
            $q->where(function ($q) use ($variants) {
                foreach ($variants as $v) {
                    $q->orWhere('name', 'like', "%{$v}%")
                      ->orWhereHas('product', function ($q) use ($v) {
                          $q->where('name', 'like', "%{$v}%")
                            ->orWhereHas('brand', fn($q) => $q->where('name', 'like', "%{$v}%"));
                      });
                }
            });
        }

        return $q->limit(50)->get();
    }

    // -----------------------------------------------------------------------
    // Result building
    // -----------------------------------------------------------------------

    protected function makeResult(Product|Variant $match, string $url): Result
    {
        $result             = new Result($this->rawQuery, $this->score($match->name ?? ''), trans('offline.mall::lang.common.product'));
        $result->url        = $url;
        $result->title      = $match->name ?? '';
        $result->text       = $match->description_short ?? '';
        $result->thumb      = $match->image;
        $result->model      = $match;
        $result->meta       = ['is_product' => true];
        $result->identifier = 'OFFLINE.Mall';

        return $result;
    }

    protected function buildUrl(string $slug, ?string $variantHashId = null): string
    {
        if (!$this->productPage || !$slug) {
            return '';
        }
        return $this->controller->pageUrl($this->productPage, array_filter([
            'slug'    => $slug,
            'variant' => $variantHashId,
        ])) ?? '';
    }

    // -----------------------------------------------------------------------
    // Relevance scoring
    // -----------------------------------------------------------------------

    /**
     * Score 3.0 — exact name match
     * Score 2.5 — name starts with query
     * Score 2.0 — all words found in name
     * Score 1.5 — all words found anywhere (name/brand)
     * Score 1.0 — partial match
     */
    protected function score(string $name): float
    {
        if (!$name) {
            return 1.0;
        }

        $nameLower  = $this->normalize($name);
        $queryLower = $this->normalize($this->rawQuery);

        if ($nameLower === $queryLower) {
            return 3.0;
        }

        if (str_starts_with($nameLower, $queryLower)) {
            return 2.7;
        }

        if (str_contains($nameLower, $queryLower)) {
            return 2.5;
        }

        // Check if all words are present in name
        foreach ($this->words as $word) {
            $found = false;
            foreach ($this->yoVariants($word) as $variant) {
                if (str_contains($nameLower, $variant)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return 1.0;
            }
        }

        return 2.0;
    }

    // -----------------------------------------------------------------------
    // Text normalization helpers
    // -----------------------------------------------------------------------

    /**
     * Lowercase + replace ё→е for consistent comparison.
     */
    protected function normalize(string $str): string
    {
        return mb_strtolower(str_replace(['Ё', 'ё'], ['е', 'е'], $str));
    }

    /**
     * Split query into meaningful tokens (min 2 chars).
     *
     * @return string[]
     */
    protected function tokenize(string $query): array
    {
        $parts = preg_split('/[\s\-\/\\\\]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($parts, fn($w) => mb_strlen($w) >= 2));
    }

    /**
     * Returns normalized word + ё variant (if different).
     * Covers both directions: е→ё and ё→е.
     *
     * @return string[]
     */
    protected function yoVariants(string $normalizedWord): array
    {
        $withYo  = str_replace('е', 'ё', $normalizedWord);
        $withYe  = str_replace('ё', 'е', $normalizedWord); // already normalized, but keep for clarity
        $result  = [$normalizedWord];

        if ($withYo !== $normalizedWord) {
            $result[] = $withYo;
        }
        if ($withYe !== $normalizedWord && !in_array($withYe, $result)) {
            $result[] = $withYe;
        }

        return $result;
    }
}
