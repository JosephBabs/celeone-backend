<?php

return [
    'root' => [
        'users', 'user_data', 'posts', 'reels', 'status', 'pages', 'followers', 'following',
        'friends', 'friendships', 'friend_requests', 'friendRequests', 'user_reactions', 'shares',
        'reports', 'interaction_events', 'notification_inbox', 'platform_requests', 'documents',
        'agenda_items', 'channels', 'videos', 'songs', 'cantiques', 'weekly_themes', 'jeunesse_quizzes',
        'creatorApplications', 'creatorContentRequests', 'creatorVerificationRequests', 'creatorRightsRequests',
        'monetizationRequests', 'social_fanout_jobs', 'social_live_sessions', 'profile_media',
        'user_subscriptions', 'subscription_packages', 'payment_transactions', 'user_feeds',
        'chatrooms', 'joinRequests', 'tvDevices', 'tvLinkCodes',
    ],
    'subcollections' => [
        'users/{uid}/friends', 'users/{uid}/saved_posts', 'users/{uid}/post_views', 'users/{uid}/watch_history',
        'users/{uid}/status_views', 'users/{uid}/status_viewers', 'users/{uid}/event_interests',
        'users/{uid}/post_alerts', 'users/{uid}/hidden_chatrooms', 'users/{uid}/media_subscriptions',
        'posts/{postId}/comments', 'posts/{postId}/reactions', 'profile_media/{mediaId}/comments',
        'chatrooms/{roomId}/messages', 'chatrooms/{roomId}/typing', 'channels/{channelId}/programs',
        'channels/{channelId}/videos', 'channels/{channelId}/podcasts', 'channels/{channelId}/comments',
        'social_live_sessions/{sessionId}/comments', 'social_live_sessions/{sessionId}/viewers',
        'social_live_sessions/{sessionId}/reactions', 'social_live_sessions/{sessionId}/blocked_commenters',
        'weekly_themes/{weekId}/event_days', 'weekly_themes/{weekId}/hymns',
    ],
    'source_project' => 'celeone-e5843',
    'migration_strategy' => 'relational_core_plus_jsonb_firebase_documents',
];
