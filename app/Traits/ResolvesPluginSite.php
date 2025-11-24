<?php

namespace App\Traits;

use App\Models\MerchantSite;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesPluginSite
{
    protected function resolveMerchantSiteOrFail(Request $request, int $merchantUserId): MerchantSite
    {
        $siteId = (int) $request->query('site_id', 0);
        $siteUrl = trim((string) $request->query('site_url', ''));

        if ($siteId <= 0 && $siteUrl === '') {
            throw ValidationException::withMessages([
                'site' => ['You must provide either site_id or site_url to target a plugin site.'],
            ]);
        }

        $query = MerchantSite::where('user_id', $merchantUserId);

        if ($siteId > 0) {
            $query->where('id', $siteId);
        }

        if ($siteUrl !== '') {
            $query->where('site_url', $siteUrl);
        }

        $site = $query->first();

        if (!$site) {
            throw ValidationException::withMessages([
                'site' => ['Plugin site not found for the authenticated merchant.'],
            ]);
        }

        return $site;
    }

    protected function formatSitePayload(MerchantSite $site): array
    {
        return [
            'id' => $site->id,
            'site_url' => $site->site_url,
            'name' => $site->name,
            'status' => $site->status,
        ];
    }
}
