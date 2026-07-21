# Conversation administration and retention

Milestone 11 adds authenticated administrator routes at `/admin/conversations`. The list is a database-paginated projection and never loads message content or diagnostic JSON. Filters cover indexed session reference, chatbot, production/test traffic, state, and activity date; sorting is allowlisted to activity, start, message count, or provider usage. Invalid pages canonicalize safely.

Detail pages load one session and its chronological messages only. User/assistant content is escaped, test traffic and channel are explicit, and the view shows safe request IDs, citations, status, latency, and numeric usage without bearer tokens, system prompts, raw provider responses, or unrelated source data.

Manual deletion is retention-only. The administrator reviews an authoritative count and maximum session ID for the current filters, receives a random server-session intent valid for 15 minutes, and types an exact count-bearing phrase. Execution reuses the reviewed maximum ID, deletes in bounded batches, cascades messages, and records only administrator ID, scope identifiers, reviewed/deleted counts, and traffic classification in logs. It never logs transcript content.

`php bin/prune-chatbot-conversations.php` first expires due active sessions and then hard-deletes terminal sessions whose copied `0|7|30|90` retention deadline has passed. Scheduled and manual paths share one database advisory lock and `CHATBOT_CONVERSATION_PURGE_BATCH_SIZE` (default `500`). Exit `2` means another run owns the lock. Schedule it daily and alert on non-zero exit. Live deletion cannot erase unexpired backups or independent content-free API Activity.

No migration is required: milestone 5 already supplied status/expiry/purge indexes and cascade semantics. Verification covers bounded parsing, production/test classification, lock exclusion, expiry-before-purge ordering, and cascading persistence behavior.
