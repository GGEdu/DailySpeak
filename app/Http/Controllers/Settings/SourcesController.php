<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NewsSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalogue of news sources, and which of them the user hides from their own feed. The catalogue is common: hiding
 * a source changes nothing for anybody else.
 */
class SourcesController extends Controller
{
    public function index(Request $request): Response
    {
        $hidden = $request->user()->hiddenSources()->pluck('news_source_id')->flip();

        return Inertia::render('settings/Sources', [
            'sources' => NewsSource::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (NewsSource $source) => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'category' => $source->category,
                    'hidden' => $hidden->has($source->id),
                    'policy_status' => $source->policy_status,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Hide the source from the user's feed, or show it again.
     */
    public function update(Request $request, NewsSource $source): RedirectResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean']]);

        if ($data['hidden']) {
            $request->user()->hiddenSources()->firstOrCreate(['news_source_id' => $source->getKey()]);
        } else {
            $request->user()->hiddenSources()->where('news_source_id', $source->getKey())->delete();
        }

        return back()->with('status', $data['hidden'] ? 'Source hidden from your feed.' : 'Source shown in your feed again.');
    }
}
