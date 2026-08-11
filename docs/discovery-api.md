# Discovery API

The discovery endpoint returns creators, published upcoming/ongoing events, and ready videos in one frontend-oriented response.

```http
GET /api/v1/general/discovery?tab=all&page=1&limit=20&search_query=vfx
Authorization: Bearer {token}
Accept: application/json
```

## Query parameters

| Parameter | Default | Rules |
|---|---:|---|
| `tab` | `all` | `all`, `creators`, `events`, or `videos` |
| `page` | `1` | Integer greater than or equal to 1 |
| `limit` | `20` | Integer from 1 to 100 |
| `search_query` | empty | Optional case-insensitive search, maximum 255 characters |

The response always includes `creators`, `events`, and `videos`. Sections outside the requested tab are empty arrays. `has_more` is true when any selected section has another page.

Challenges are intentionally not included until the challenge data model is implemented.

Creator compatibility fields that do not yet have a database source are returned as stable defaults:

- `is_live: false`
- `style: null`
- `tools: []`

Trending videos are ordered by views, likes, and recency. Only videos with `status: ready` are returned. Events must be published and not ended. The viewer fields are calculated for the authenticated user.
