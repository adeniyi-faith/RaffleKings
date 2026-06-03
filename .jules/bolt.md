## 2024-05-23 - Prevent N+1 queries in loop
**Learning:** In WordPress plugin development, running `get_userdata` or `get_user_meta` inside a loop for different users triggers N+1 database queries, slowing down performance.
**Action:** Use `cache_users($user_ids)` and `update_meta_cache('user', $user_ids)` before the loop to prime the WordPress internal cache and reduce database queries.
