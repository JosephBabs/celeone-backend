<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportFirebaseData extends Command
{
    protected $signature = 'firebase:import {file=storage/app/firebase-export.ndjson} {--dry-run} {--from-database}';
    protected $description = 'Import Firebase export NDJSON while preserving unmapped documents in JSONB.';

    public function handle(): int
    {
        if ($this->option('from-database')) {
            return $this->importExistingDatabaseDocuments();
        }
        $inputFile = (string) $this->argument('file');

        $file = str_starts_with($inputFile, '/')
            ? $inputFile
            : base_path($inputFile);
        if (! is_file($file)) {
            $this->error("Export file not found: {$file}");
            return self::FAILURE;
        }
        $count = 0;
        $handle = fopen($file, 'rb');
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (! is_array($row) || empty($row['collectionPath']) || empty($row['documentId'])) continue;
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            if (($row['collectionPath'] ?? null) === 'posts') {
                $data = $this->protectLaravelPostMetrics((string) $row['documentId'], $data);
            }
            $sourcePath = (string) ($row['collectionPath'].'/'.($row['documentId'] ?? ''));
            $checksum = hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! $this->option('dry-run')) {
                DB::table('firebase_documents')->updateOrInsert(
                    ['collection_path' => $row['collectionPath'], 'document_id' => $row['documentId']],
                    ['parent_document_path' => $row['parentDocumentPath'] ?? null, 'payload' => json_encode($data), 'firebase_created_at' => $row['createTime'] ?? null, 'firebase_updated_at' => $row['updateTime'] ?? null, 'source_path' => $sourcePath, 'source_checksum' => $checksum, 'import_status' => 'staged', 'updated_at' => now(), 'created_at' => now()],
                );
                DB::table('legacy_snapshots')->updateOrInsert(
                    ['source_path' => $sourcePath],
                    ['source_collection' => $row['collectionPath'], 'source_document_id' => $row['documentId'], 'source_created_at' => $row['createTime'] ?? null, 'source_updated_at' => $row['updateTime'] ?? null, 'source_checksum' => $checksum, 'payload' => json_encode($data), 'updated_at' => now(), 'created_at' => now()],
                );
                DB::table('migration_ledger')->updateOrInsert(
                    ['source_path' => $sourcePath, 'source_checksum' => $checksum],
                    ['source_updated_at' => $row['updateTime'] ?? null, 'status' => 'staged', 'updated_at' => now(), 'created_at' => now()],
                );
                $this->importKnownRecord($row['collectionPath'], $row['documentId'], $data);
            }
            $count++;
            if ($count % 500 === 0) $this->info("Processed {$count} documents...");
        }
        fclose($handle);
        $this->info("Processed {$count} Firebase documents.");
        return self::SUCCESS;
    }

    private function importExistingDatabaseDocuments(): int
    {
        $count = 0;
        foreach (DB::table('firebase_documents')->orderBy('id')->cursor() as $row) {
            $data = json_decode((string) $row->payload, true);
            if (! is_array($data)) continue;
            if (! $this->option('dry-run')) {
                $sourcePath = (string) ($row->source_path ?: $row->collection_path.'/'.$row->document_id);
                $checksum = (string) ($row->source_checksum ?: hash('sha256', (string) $row->payload));
                DB::table('legacy_snapshots')->updateOrInsert(
                    ['source_path' => $sourcePath],
                    ['source_collection' => $row->collection_path, 'source_document_id' => $row->document_id, 'source_updated_at' => $row->firebase_updated_at, 'source_checksum' => $checksum, 'payload' => $row->payload, 'updated_at' => now(), 'created_at' => now()],
                );
                DB::table('migration_ledger')->updateOrInsert(
                    ['source_path' => $sourcePath, 'source_checksum' => $checksum],
                    ['source_updated_at' => $row->firebase_updated_at, 'status' => 'staged', 'updated_at' => now(), 'created_at' => now()],
                );
                $this->importKnownRecord((string) $row->collection_path, (string) $row->document_id, $data);
            }
            $count++;
        }
        $this->info("Processed {$count} existing Firebase documents from PostgreSQL.");
        return self::SUCCESS;
    }

    private function importKnownRecord(string $collection, string $firebaseId, array $data): void
    {
        if ($collection === 'user_data') {
            $uid = (string) ($data['uid'] ?? $data['userId'] ?? $firebaseId);
            $user = User::where('legacy_firebase_uid', $uid)->orWhere('firebase_uid', $uid)->first();
            if (! $user && ! empty($data['email'])) $user = User::where('email', strtolower((string) $data['email']))->first();
            if (! $user) {
                $user = User::create(['firebase_uid' => $uid, 'legacy_firebase_uid' => $uid, 'name' => $data['name'] ?? $data['displayName'] ?? 'CeleOne user', 'email' => strtolower((string) ($data['email'] ?? $uid.'@migrated.celeone.local')), 'password' => Hash::make(Str::random(64)), 'role' => 'user']);
            }
            DB::table('user_profiles')->updateOrInsert(
                ['user_id' => $user->id],
                ['username' => $data['username'] ?? $data['userName'] ?? null, 'bio' => $data['bio'] ?? $data['about'] ?? null, 'phone' => $data['phoneNumber'] ?? $data['phone'] ?? null, 'location' => $data['location'] ?? $data['city'] ?? null, 'legacy_id' => $firebaseId, 'legacy_collection' => $collection, 'legacy_path' => $collection.'/'.$firebaseId, 'legacy_payload' => json_encode($data), 'legacy_created_at' => $this->timestamp($data['createdAt'] ?? null), 'legacy_updated_at' => $this->timestamp($data['updatedAt'] ?? null), 'migrated_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );
            DB::table('user_data')->updateOrInsert(
                ['firebase_uid' => $uid, 'legacy_collection' => $collection],
                ['user_id' => $user->id, 'display_name' => $data['displayName'] ?? $data['name'] ?? null, 'username' => $data['username'] ?? $data['userName'] ?? null, 'bio' => $data['bio'] ?? $data['about'] ?? null, 'phone_number' => $data['phoneNumber'] ?? $data['phone'] ?? null, 'avatar_url' => $data['avatarUrl'] ?? $data['photoURL'] ?? null, 'role' => $data['role'] ?? 'user', 'profile_data' => json_encode($data), 'legacy_path' => $collection.'/'.$firebaseId, 'legacy_payload' => json_encode($data), 'legacy_created_at' => $this->timestamp($data['createdAt'] ?? null), 'legacy_updated_at' => $this->timestamp($data['updatedAt'] ?? null), 'migrated_at' => now(), 'migration_checksum' => hash('sha256', json_encode($data)), 'updated_at' => now(), 'created_at' => now()],
            );
            $user->forceFill(['legacy_data' => array_merge($user->legacy_data ?? [], $data), 'legacy_payload' => $data, 'legacy_collection' => $collection, 'legacy_path' => $collection.'/'.$firebaseId, 'migrated_at' => now()])->save();
        } elseif ($collection === 'users') {
            $claims = is_array($data['customClaims'] ?? null) ? $data['customClaims'] : (is_array($data['claims'] ?? null) ? $data['claims'] : []);
            $user = User::where('legacy_firebase_uid', $firebaseId)->orWhere('firebase_uid', $firebaseId)->first() ?? new User();
            $roleValue = $data['role'] ?? $claims['role'] ?? null;
            if ($roleValue === null && ($data['isSuperAdmin'] ?? false)) $roleValue = 'super_admin';
            if ($roleValue === null && (($data['isAdmin'] ?? false) || ($data['admin'] ?? false))) $roleValue = 'admin';
            $role = strtolower((string) ($roleValue ?? $user->role ?? 'user'));
            $role = in_array($role, ['admin', 'super_admin'], true) ? $role : 'user';
            $user->forceFill([
                'firebase_uid' => $user->firebase_uid ?: $firebaseId,
                'legacy_firebase_uid' => $firebaseId,
                'name' => $data['name'] ?? $data['displayName'] ?? $user->name ?? 'Cèlè One user',
                'email' => strtolower((string) ($data['email'] ?? $user->email ?? $firebaseId.'@migrated.celeone.local')),
                // Never overwrite an imported Firebase identity's password/token state.
                'password' => $user->password ?: Hash::make(Str::random(64)),
                'avatar_url' => $data['avatarUrl'] ?? $data['photoURL'] ?? $user->avatar_url,
                'role' => $role,
                'firebase_disabled' => (bool) ($data['disabled'] ?? false),
                'firebase_custom_claims' => $claims,
                'legacy_data' => array_merge($user->legacy_data ?? [], $data),
                'legacy_payload' => $data,
                'legacy_collection' => $collection,
                'legacy_path' => $collection.'/'.$firebaseId,
                'legacy_created_at' => $this->timestamp($data['createdAt'] ?? null),
                'legacy_updated_at' => $this->timestamp($data['updatedAt'] ?? null),
                'migrated_at' => now(),
            ])->save();
            // Keep the denormalized profile copy in agreement with users.role.
            DB::table('user_data')->where('user_id', $user->id)->update(['role' => $role, 'updated_at' => now()]);
        } elseif ($collection === 'pages') {
            Page::updateOrCreate(['firebase_id' => $firebaseId], ['name' => $data['name'] ?? 'Untitled page', 'description' => $data['description'] ?? null, 'avatar_url' => $data['logoUrl'] ?? $data['avatarUrl'] ?? null, 'status' => $data['status'] ?? 'active', 'legacy_data' => $data]);
        } elseif ($collection === 'posts') {
            $user = ! empty($data['authorId']) ? User::where('firebase_uid', $data['authorId'])->first() : null;
            $page = ! empty($data['pageId']) ? Page::where('firebase_id', $data['pageId'])->first() : null;
            $post = Post::updateOrCreate(
                ['firebase_id' => $firebaseId],
                ['user_id' => $user?->id, 'page_id' => $page?->id, 'text_content' => $data['text'] ?? $data['body'] ?? null, 'post_type' => $data['type'] ?? 'status', 'audience' => $data['audience'] ?? 'public', 'status' => $data['status'] ?? 'published', 'legacy_data' => $data],
            );
            $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
            // Seed the SQL counters exactly once from the legacy post. Once a
            // post_metrics row exists, Laravel endpoints own it permanently.
            DB::table('post_metrics')->insertOrIgnore([
                'post_id' => $post->id,
                'reactions_count' => max(0, (int) ($metrics['reactions'] ?? $data['reactions_count'] ?? $data['likes'] ?? 0)),
                'comments_count' => max(0, (int) ($metrics['comments'] ?? $data['comments_count'] ?? $data['commentCount'] ?? $data['comments'] ?? 0)),
                'shares_count' => max(0, (int) ($metrics['shares'] ?? $data['shares_count'] ?? $data['shares'] ?? 0)),
                'views_count' => max(0, (int) ($metrics['views'] ?? $data['views_count'] ?? $data['viewCount'] ?? $data['views'] ?? 0)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif (in_array($collection, ['academyCourses', 'academyLessons', 'academyVideos', 'academyExams'], true)) {
            $table = match ($collection) {
                'academyCourses' => 'academy_courses',
                'academyLessons' => 'academy_lessons',
                'academyVideos' => 'academy_videos',
                default => 'academy_exams',
            };
            DB::table($table)->updateOrInsert(
                ['firebase_id' => $firebaseId],
                ['title' => $data['title'] ?? null, 'subtitle' => $data['subtitle'] ?? null, 'description' => $data['description'] ?? null, 'image_url' => $data['imageUrl'] ?? null, 'video_url' => $data['videoUrl'] ?? null, 'duration' => is_numeric($data['duration'] ?? null) ? (int) $data['duration'] : null, 'status' => $data['status'] ?? null, 'seeded_by' => $data['seededBy'] ?? null, 'archived_at' => $this->timestamp($data['archivedAt'] ?? null), 'legacy_data' => json_encode($data), 'updated_at' => now(), 'created_at' => now()],
            );
        } elseif ($collection === 'academyCertificates') {
            DB::table('academy_certificates')->updateOrInsert(
                ['firebase_id' => $firebaseId],
                ['user_id' => $data['userId'] ?? null, 'user_email' => $data['userEmail'] ?? null, 'title' => $data['title'] ?? null, 'subtitle' => $data['subtitle'] ?? null, 'description' => $data['description'] ?? null, 'status' => $data['status'] ?? null, 'seeded_by' => $data['seededBy'] ?? null, 'issued_at_text' => $data['issuedAtText'] ?? null, 'legacy_data' => json_encode($data), 'updated_at' => now(), 'created_at' => now()],
            );
        } elseif ($collection === 'academyEnrollments') {
            DB::table('academy_enrollments')->updateOrInsert(
                ['firebase_id' => $firebaseId],
                ['user_id' => $data['userId'] ?? '', 'course_id' => $data['courseId'] ?? null, 'course_title' => $data['courseTitle'] ?? null, 'progress' => is_numeric($data['progress'] ?? null) ? (int) $data['progress'] : null, 'completed_lesson_ids' => json_encode($data['completedLessonIds'] ?? []), 'review_status' => $data['reviewStatus'] ?? null, 'reviewed_by' => $data['reviewedBy'] ?? null, 'reviewed_at' => $this->timestamp($data['reviewedAt'] ?? null), 'status' => $data['status'] ?? null, 'legacy_data' => json_encode($data), 'updated_at' => now(), 'created_at' => now()],
            );
        } elseif ($collection === 'admin_audit_logs') {
            DB::table('admin_audit_logs')->updateOrInsert(
                ['firebase_id' => $firebaseId],
                ['action' => $data['action'] ?? null, 'admin_uid' => $data['adminUid'] ?? null, 'admin_email' => $data['adminEmail'] ?? null, 'target_id' => $data['targetId'] ?? null, 'target_type' => $data['targetType'] ?? null, 'collection' => $data['collection'] ?? null, 'document_id' => $data['documentId'] ?? null, 'environment' => $data['environment'] ?? null, 'as_role' => $data['as'] ?? null, 'created_at_ms' => $data['createdAtMs'] ?? null, 'fields' => json_encode($data['fields'] ?? []), 'legacy_data' => json_encode($data), 'updated_at' => now(), 'created_at' => now()],
            );
        } elseif ($collection === 'app_config') {
            DB::table('app_configs')->updateOrInsert(
                ['key' => $firebaseId],
                ['value' => json_encode($data), 'public' => true, 'legacy_id' => $firebaseId, 'legacy_collection' => $collection, 'legacy_path' => $collection.'/'.$firebaseId, 'legacy_payload' => json_encode($data), 'legacy_created_at' => $this->timestamp($data['createdAt'] ?? null), 'legacy_updated_at' => $this->timestamp($data['updatedAt'] ?? null), 'migrated_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );
        } else {
            $root = explode('/', trim($collection, '/'))[0] ?? $collection;
            $table = config('celeone_domains.collections.'.$root);
            if ($table && \Illuminate\Support\Facades\Schema::hasTable($table)) {
                $path=$collection.'/'.$firebaseId;
                $columns = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing($table));
                if (isset($columns['public_id'])) $key = ['public_id' => $firebaseId];
                elseif (isset($columns['legacy_id'])) $key = ['legacy_id' => $firebaseId, 'legacy_collection' => $root];
                elseif (isset($columns['firebase_id'])) $key = ['firebase_id' => $firebaseId];
                else $key = null;
                $values = ['user_id'=>$this->userId($data),'parent_type'=>$data['parentType']??null,'parent_id'=>(string)($data['parentId']??$data['postId']??$data['pageId']??$data['roomId']??'' ) ?: null,'status'=>$data['status']??'published','title'=>$data['title']??$data['name']??$data['parishName']??null,'name'=>$data['name']??$data['title']??$data['parishName']??'Migrated record','body'=>$data['body']??$data['text']??$data['description']??null,'description'=>$data['description']??$data['body']??null,'target_type'=>$data['targetType']??$data['type']??'legacy','target_id'=>(string)($data['targetId']??$firebaseId),'reason'=>$data['reason']??'legacy_import','shareable_type'=>$data['shareableType']??$data['type']??$root,'shareable_id'=>(string)($data['shareableId']??$data['postId']??$data['targetId']??$firebaseId),'destination'=>$data['destination']??null,'data'=>json_encode($data),'legacy_id'=>$firebaseId,'legacy_collection'=>$root,'legacy_path'=>$path,'legacy_payload'=>json_encode($data),'legacy_created_at'=>$this->timestamp($data['createdAt']??null),'legacy_updated_at'=>$this->timestamp($data['updatedAt']??null),'migrated_at'=>now(),'created_at'=>now(),'updated_at'=>now()];
                if ($key !== null) DB::table($table)->updateOrInsert($key, array_intersect_key($values, $columns));
                DB::table('migration_ledger')->where('source_path',$path)->update(['target_type'=>$table,'target_id'=>$firebaseId,'status'=>'imported','processed_at'=>now(),'updated_at'=>now()]);
            }
        }
    }

    /**
     * During the migration Firebase remains a compatibility input for legacy
     * clients, but Laravel owns engagement counters. Never allow a periodic
     * Firebase root-post export to roll reactions/comments/views/shares back.
     */
    private function protectLaravelPostMetrics(string $firebaseId, array $incoming): array
    {
        $post = Post::where('firebase_id', $firebaseId)
            ->orWhere('id', ctype_digit($firebaseId) ? (int) $firebaseId : -1)
            ->first();

        $existingRow = DB::table('firebase_documents')
            ->where('collection_path', 'posts')
            ->where('document_id', $firebaseId)
            ->first();
        $existing = $existingRow
            ? (json_decode((string) $existingRow->payload, true) ?: [])
            : [];

        $existingMetrics = is_array($existing['metrics'] ?? null) ? $existing['metrics'] : [];
        $incomingMetrics = is_array($incoming['metrics'] ?? null) ? $incoming['metrics'] : [];

        if ($post) {
            $metric = DB::table('post_metrics')->where('post_id', $post->id)->first();
            if ($metric) {
                $reactions = (int) $metric->reactions_count;
                $comments = (int) $metric->comments_count;
                $shares = (int) $metric->shares_count;
                $views = (int) $metric->views_count;
                $incoming['likes'] = $reactions;
                $incoming['reactions_count'] = $reactions;
                $incoming['commentCount'] = $comments;
                $incoming['comments'] = $comments;
                $incoming['comments_count'] = $comments;
                $incoming['shares'] = $shares;
                $incoming['shares_count'] = $shares;
                $incoming['viewCount'] = $views;
                $incoming['views'] = $views;
                $incoming['views_count'] = $views;
                $incoming['metrics'] = array_merge($incomingMetrics, [
                    'reactions' => $reactions,
                    'comments' => $comments,
                    'shares' => $shares,
                    'views' => $views,
                ]);
            }
        } elseif ($existing) {
            foreach (['likes','reactions_count','commentCount','comments','comments_count','shares','shares_count','viewCount','views','views_count','likedBy','viewerPreview'] as $key) {
                if (array_key_exists($key, $existing)) $incoming[$key] = $existing[$key];
            }
            $incoming['metrics'] = array_merge($incomingMetrics, $existingMetrics);
        }

        if (isset($existing['backendMetricsUpdatedAtMs'])) {
            $incoming['backendMetricsUpdatedAtMs'] = $existing['backendMetricsUpdatedAtMs'];
        }

        return $incoming;
    }

    private function userId(array $data): ?int
    {
        $uid=$data['userId']??$data['uid']??$data['authorId']??$data['ownerId']??null;
        return $uid ? User::where('firebase_uid',(string)$uid)->orWhere('legacy_firebase_uid',(string)$uid)->value('id') : null;
    }

    private function timestamp(mixed $value): mixed
    {
        if (is_string($value) && $value!=='') return $value;
        if (is_numeric($value)) return \Carbon\Carbon::createFromTimestampMs((int)$value);
        if (is_array($value) && isset($value['_seconds'])) return \Carbon\Carbon::createFromTimestamp((int)$value['_seconds']);
        if (is_array($value) && isset($value['seconds'])) return \Carbon\Carbon::createFromTimestamp((int)$value['seconds']);
        return null;
    }
}
