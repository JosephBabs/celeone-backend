<?php
namespace App\Services\Feed;

/** Pure ranking/diversity pass. Unselected candidates remain in the cursor. */
class FeedMixer
{
    public function page(array $candidates, int $limit, array $state): array
    {
        $items = [];
        $counts = [];
        $last = $state['last_author'] ?? null;
        $streak = (int)($state['streak'] ?? 0);
        $position = (int)($state['position'] ?? 0);
        while ($candidates && count($items) < $limit) {
            $eligible = array_filter($candidates, fn($row) => $row['author'] !== $last || $streak < config('feed.max_consecutive_author',2));
            if (!$eligible) $eligible = $candidates; // Only one source remains.
            $underCap = array_filter($eligible, fn($row) => ($counts[$row['author']] ?? 0) < config('feed.author_page_cap',3));
            if ($underCap) $eligible = $underCap;
            $suggestionSlot = ($position + 1) % config('feed.suggestion_interval',4) === 0;
            $preferred = array_filter($eligible, fn($row) => $suggestionSlot ? $row['suggestion'] : !$row['suggestion']);
            if ($preferred) $eligible = $preferred;
            uasort($eligible, function($a,$b) {
                $recent=(int)($b['recent_connection'] ?? false) <=> (int)($a['recent_connection'] ?? false);
                if ($recent!==0) return $recent;
                if (!empty($a['recent_connection']) && !empty($b['recent_connection'])) {
                    return $b['timestamp'] <=> $a['timestamp'] ?: strcmp($b['id'],$a['id']);
                }
                return $b['score'] <=> $a['score'] ?: $b['timestamp'] <=> $a['timestamp'] ?: strcmp($b['id'],$a['id']);
            });
            $key = array_key_first($eligible);
            $chosen = $candidates[$key];
            unset($candidates[$key]);
            $kind = $suggestionSlot && $chosen['suggestion'] ? 'SUGGESTION' : 'ORGANIC_POST';
            $items[] = ['id'=>$chosen['id'], 'feedItemType'=>$kind, 'feedReason'=>$chosen['reason'], 'rankingScore'=>(float)$chosen['score']];
            $streak = $chosen['author'] === $last ? $streak + 1 : 1;
            $last = $chosen['author'];
            $counts[$last] = ($counts[$last] ?? 0) + 1;
            $position++;
        }
        return ['items'=>$items, 'pending'=>array_values(array_column($candidates,'id')), 'last_author'=>$last, 'streak'=>$streak, 'position'=>$position];
    }
}
