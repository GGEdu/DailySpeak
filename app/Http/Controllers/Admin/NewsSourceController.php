<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNewsSourceRequest;
use App\Jobs\CheckNewsSourcePolicy;
use App\Jobs\FetchNewsSource;
use App\Models\NewsSource;
use App\Services\News\SourcePolicyResult;
use App\Services\News\SourceUsagePolicy;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin page to manage the RSS feeds read by `news:fetch`.
 */
class NewsSourceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Sources', [
            'sources' => NewsSource::query()
                ->withCount('articles')
                ->orderBy('name')
                ->get()
                ->map(fn (NewsSource $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'feed_url' => $source->feed_url,
                    'category' => $source->category,
                    'is_active' => $source->is_active,
                    'articles_count' => $source->articles_count,
                    'last_fetched_at' => $source->last_fetched_at,
                    'last_error' => $source->last_error,
                    // Admins only: this page is the one place the usage-policy result is shown.
                    'policy_status' => $source->policy_status,
                    'policy_note' => $source->policy_note,
                    'policy_checked_at' => $source->policy_checked_at,
                ]),
            'categories' => collect(config('news.categories'))
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    /**
     * Save the source and queue its usage-terms check. A problem is a heads-up, never a refusal.
     *
     * The robots.txt files are read by the queued check, so the result is not known yet here. The one heads-up
     * shown now is a publisher condition, which needs no download; the robots.txt findings appear in the row.
     */
    public function store(StoreNewsSourceRequest $request, SourceUsagePolicy $policy): RedirectResponse
    {
        $source = NewsSource::create($request->validated());
        $this->queueTermsCheck($source);

        $conditions = $policy->publisherConditions($source->feed_url, $request->feedItems());

        if ($conditions === []) {
            return back()->with('status', "{$source->name} added. It will be read in the next daily run; its usage terms are being checked.");
        }

        return back()->with('warning', "{$source->name} added. Heads-up: ".implode(' ', $conditions));
    }

    /**
     * Pause or resume a source, or check its usage terms again (`check_policy`).
     *
     * The re-check shares this route so the admin page needs no route of its own.
     */
    public function update(Request $request, NewsSource $source): RedirectResponse
    {
        if ($request->boolean('check_policy')) {
            $this->queueTermsCheck($source);

            return back()->with('status', "{$source->name} is being checked again. Its result appears in its row when the check ends.");
        }

        $source->update($request->validate([
            'is_active' => ['sometimes', 'required', 'boolean'],
            'category' => ['sometimes', 'required', Rule::in(array_keys(config('news.categories')))],
        ]));

        return back();
    }

    /**
     * Delete a source; its articles stay in the feed.
     */
    public function destroy(NewsSource $source): RedirectResponse
    {
        $source->delete();

        return back()->with('status', "{$source->name} deleted.");
    }

    /**
     * Mark the source as being checked, so its row says so, and queue the check.
     */
    private function queueTermsCheck(NewsSource $source): void
    {
        $source->forceFill(['policy_status' => SourcePolicyResult::CHECKING])->save();

        CheckNewsSourcePolicy::dispatch($source->id);
    }

    /**
     * Harvest a single source now, on its own queue (see FetchNewsSource for why it is not `news:fetch`).
     */
    public function fetch(NewsSource $source, UniqueLock $unique): RedirectResponse
    {
        $job = new FetchNewsSource($source->id);

        // The job is unique per source: a plain dispatch made while one is queued or running is dropped without a
        // word. Taking the job's lock here says whether this click queued a fetch. The job is then sent to the
        // queue directly, because the lock is already held for it.
        if (! $unique->acquire($job)) {
            return back()->with('status', "{$source->name} is already being fetched. Its new articles will appear in the feed as they are summarised.");
        }

        Bus::dispatch($job);

        return back()->with('status', "Fetching {$source->name}… new articles will appear in the feed as they are summarised.");
    }
}
