-- All writers (REST, compatibility batch/transactions, and Firebase import)
-- maintain the same feed index atomically. The payload is stored only once.
CREATE OR REPLACE FUNCTION celeone_feed_metadata() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p jsonb; raw_time text; raw_tags jsonb;
BEGIN
    IF NEW.collection_path NOT IN ('posts','reels') THEN RETURN NEW; END IF;
    p := COALESCE(NEW.payload::jsonb, '{}'::jsonb);
    NEW.feed_page_id := NULLIF(COALESCE(p->>'pageId', p->>'page_id'), '');
    NEW.feed_author_id := LEFT(COALESCE(NULLIF(p->>'authorId',''), NULLIF(p->>'userId',''), NULLIF(p->>'user_id',''), NULLIF(p->>'actorId',''), 'legacy:' || NEW.document_id),191);
    NEW.feed_owner_id := LEFT(COALESCE(NULLIF(p->>'actorOwnerId',''), NULLIF(p->>'userId',''), NEW.feed_author_id),191);
    NEW.feed_status := LEFT(LOWER(COALESCE(NULLIF(p->>'status',''), 'published')),30);
    IF p->>'deleted' = 'true' OR p->>'isDeleted' = 'true' OR p->>'hidden' = 'true' THEN NEW.feed_status := 'deleted'; END IF;
    NEW.feed_audience := LEFT(LOWER(COALESCE(NULLIF(p->>'audience',''), NULLIF(p->>'visibility',''), 'public')),30);
    raw_time := COALESCE(p->>'createdAtMs', p->>'created_at_ms');
    BEGIN
        IF raw_time ~ '^\d{1,16}(\.\d+)?$' THEN NEW.feed_created_at_ms := raw_time::numeric::bigint;
        ELSIF COALESCE(p#>>'{createdAt,seconds}',p#>>'{createdAt,_seconds}') ~ '^\d{1,12}$' THEN
            NEW.feed_created_at_ms := COALESCE(p#>>'{createdAt,seconds}',p#>>'{createdAt,_seconds}')::bigint * 1000;
        ELSIF jsonb_typeof(p->'createdAt') = 'string' THEN NEW.feed_created_at_ms := EXTRACT(EPOCH FROM (p->>'createdAt')::timestamptz) * 1000;
        ELSE NEW.feed_created_at_ms := EXTRACT(EPOCH FROM COALESCE(NEW.firebase_created_at,NEW.created_at,now())) * 1000;
        END IF;
    EXCEPTION WHEN OTHERS THEN NEW.feed_created_at_ms := EXTRACT(EPOCH FROM COALESCE(NEW.created_at,now())) * 1000;
    END;
    raw_tags := CASE WHEN jsonb_typeof(p->'tags')='array' THEN p->'tags' ELSE '[]'::jsonb END
        || CASE WHEN jsonb_typeof(p->'hashtags')='array' THEN p->'hashtags' ELSE '[]'::jsonb END
        || CASE WHEN jsonb_typeof(p->'category')='string' THEN jsonb_build_array(p->>'category') ELSE '[]'::jsonb END;
    SELECT COALESCE(jsonb_agg(tag),'[]'::jsonb) INTO NEW.feed_tags FROM (
        SELECT DISTINCT LEFT(LOWER(LTRIM(BTRIM(value),'#')),64) AS tag
        FROM jsonb_array_elements_text(raw_tags) WHERE BTRIM(value) <> '' LIMIT 30
    ) normalized;
    NEW.feed_revision := md5(p::text);
    raw_time := COALESCE(p#>>'{metrics,reactions}',p#>>'{metrics,likes}',p->>'likes','0');
    NEW.feed_popularity := CASE WHEN raw_time ~ '^\d{1,12}$' THEN raw_time::numeric ELSE 0 END;
    RETURN NEW;
END $$;
CREATE TRIGGER celeone_feed_metadata BEFORE INSERT OR UPDATE OF payload, collection_path, document_id
ON firebase_documents FOR EACH ROW EXECUTE FUNCTION celeone_feed_metadata();
