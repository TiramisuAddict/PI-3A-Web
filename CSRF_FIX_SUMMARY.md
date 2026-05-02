# CSRF Token Fix - Complete Implementation

## Problem
AJAX buttons (Like, Participate) were showing "CSRF token invalid" errors because tokens were not being properly extracted and sent with fetch requests.

## Solution Overview
Restructured button architecture and JavaScript to properly handle CSRF tokens using `data-csrf` attributes and `URLSearchParams` for form submission.

---

## Changes Made to `templates/employe/feed.html.twig`

### 1. **Like Button** (Lines 229-240)
**Before:** Form-based submission (referenced missing form)
**After:** 
```html
<button type="button" 
        class="btn btn-ghost-secondary w-100 border-0 feed-action-btn" 
        data-post-id="{{ pid }}" 
        data-action="like"
        data-csrf="{{ csrf_token('feed_like_' ~ pid) }}"
        style="flex: 1; border-radius: 0; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all 0.2s ease;">
    <i class="ti {% if liked %}ti-heart-filled text-danger{% else %}ti-heart{% endif %}" style="transition: all 0.2s ease;"></i>
    <span class="feed-action-count">{{ like_n }}</span>
</button>
```

**Key Changes:**
- Added `data-csrf="{{ csrf_token('feed_like_' ~ pid) }}"` - CSRF token stored in button attribute
- Added `data-action="like"` - Identifies action type for JavaScript handler
- Changed to common class `feed-action-btn` for unified JavaScript handling
- Icon changes from `ti-heart` to `ti-heart-filled text-danger` when liked
- Added CSS transition for smooth animation

### 2. **Participate Button** (Lines 257-276)
**Before:** Form-based submission (referenced missing form)
**After:**
```html
<button type="button" 
        class="btn {% if participating %}btn-primary{% else %}btn-ghost-secondary{% endif %} w-100 border-0 feed-action-btn" 
        data-post-id="{{ pid }}" 
        data-action="participate"
        data-csrf="{{ csrf_token('feed_participate_' ~ pid) }}"
        style="flex: 1; border-radius: 0; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all 0.2s ease;">
    <i class="ti {% if participating %}ti-circle-check-filled{% else %}ti-circle-check{% endif %}" style="transition: all 0.2s ease;"></i>
    <span class="feed-action-text">{% if participating %}Annuler{% else %}Participer{% endif %}</span>
</button>
```

**Key Changes:**
- Added `data-csrf="{{ csrf_token('feed_participate_' ~ pid) }}"` - CSRF token stored in button attribute
- Added `data-action="participate"` - Identifies action type for JavaScript
- Button class toggles: `btn-primary` when participating, `btn-ghost-secondary` when not
- Icon and text change dynamically based on participation status

### 3. **Comment Button** (No significant change)
Kept as-is since form has proper hidden token:
```html
<form class="input-group input-group-flat feed-comment-form" data-post-id="{{ pid }}">
    <input type="hidden" name="_token" value="{{ csrf_token('feed_comment_' ~ pid) }}">
    ...
</form>
```

### 4. **Stats Row** - Added Update-friendly IDs
**Before:**
```html
<span class="d-flex align-items-center gap-1">
    <i class="ti ti-heart-filled text-danger"></i>
    <span class="text-danger">{{ like_n }}</span>
</span>
```

**After:**
```html
<span class="d-flex align-items-center gap-1" id="like-count-{{ pid }}">
    <i class="ti ti-heart-filled text-danger"></i>
    <span class="text-danger">{{ like_n }}</span>
</span>
```

Added IDs to all stat spans:
- `id="like-count-{{ pid }}"` - Updated when like count changes
- `id="com-count-{{ pid }}"` - Updated when comment count changes
- `id="part-count-{{ pid }}"` - Updated when participate count changes

---

## JavaScript Implementation (Lines 285+)

### New Helper Function: `sendAction()`
```javascript
async function sendAction(url, csrfToken, extraData = {}) {
    var body = new URLSearchParams();
    body.append('_token', csrfToken);
    for (var key in extraData) {
        body.append(key, extraData[key]);
    }
    
    var response = await fetch(url, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
        credentials: 'same-origin',
    });
    
    return response.json();
}
```

**Key Features:**
- Uses `URLSearchParams` to properly encode form data
- Includes `X-Requested-With: XMLHttpRequest` header (identifies AJAX requests)
- Uses `credentials: 'same-origin'` for cookie inclusion
- Returns JSON response for handling

### Like Button Handler
```javascript
var data = await sendAction('/employe/like/' + postId, csrfToken);

if (data.success) {
    // Animate icon with CSS animation
    icon.classList.add('like-animated');
    setTimeout(() => icon.classList.remove('like-animated'), 300);
    
    // Update icon from ti-heart → ti-heart-filled (or vice versa)
    if (data.liked) {
        icon.classList.remove('ti-heart');
        icon.classList.add('ti-heart-filled', 'text-danger');
    } else {
        icon.classList.remove('ti-heart-filled', 'text-danger');
        icon.classList.add('ti-heart');
    }
    
    // Update button count
    countSpan.textContent = data.count;
    
    // Update stats row count
    var statsSpan = document.getElementById('like-count-' + postId);
    if (statsSpan) {
        statsSpan.innerHTML = '<i class="ti ti-heart-filled text-danger"></i><span class="text-danger">' + data.count + '</span>';
    }
}
```

**Updates:**
- Fetches CSRF from `btn.getAttribute('data-csrf')`
- Sends POST to `/employe/like/{postId}` with CSRF token in body
- Updates icon, color, and counts on success
- Shows scale animation on like

### Comment Button Handler
```javascript
var section = document.getElementById('comments-section-' + postId);
section.style.display = isHidden ? 'block' : 'none';
if (isHidden) {
    var input = section.querySelector('.feed-comment-input');
    if (input) setTimeout(() => input.focus(), 100);
}
```

**Features:**
- Toggles comment section visibility
- Auto-focuses text input when section opens

### Comment Form Handler
```javascript
var data = await sendAction('/employe/comment/' + postId, csrfToken, {contenu: contenu});

if (data.success) {
    // Update count in button
    countSpan.textContent = data.count;
    
    // Update stats row
    statsSpan.innerHTML = '<i class="ti ti-message-circle"></i>' + data.count + ' commentaire' + plural;
    
    // Clear input
    input.value = '';
    
    // Prepend new comment to preview
    var commentHtml = '<div class="mb-2 pb-2 border-bottom">...';
    previewContainer.insertBefore(tempDiv.firstChild, previewContainer.firstChild);
    
    // Keep section visible
    section.style.display = 'block';
}
```

**Features:**
- Sends CSRF token from form's hidden input
- Updates counts immediately
- Prepends new comment to preview list
- Clears input field after posting
- Keeps comments section expanded

### Participate Button Handler
```javascript
var data = await sendAction('/employe/participate/' + postId, csrfToken);

if (data.success) {
    var isParticipating = data.participating;
    
    // Update icon
    if (isParticipating) {
        icon.classList.add('ti-circle-check-filled');
    } else {
        icon.classList.remove('ti-circle-check-filled');
    }
    
    // Update text
    textSpan.textContent = isParticipating ? 'Annuler' : 'Participer';
    
    // Toggle button class
    if (isParticipating) {
        btn.classList.remove('btn-ghost-secondary');
        btn.classList.add('btn-primary');
    } else {
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-ghost-secondary');
    }
    
    // Update counts
    countSpan.textContent = data.count;
}
```

**Updates:**
- Fetches CSRF from `btn.getAttribute('data-csrf')`
- Sends POST to `/employe/participate/{postId}` with CSRF token
- Updates icon, text, button class, and counts
- Shows error alert if capacity exceeded

---

## FeedController.php - No Changes Required

**All routes already correctly validate CSRF:**

```php
// Like route (line 157)
if (!$this->isCsrfTokenValid("feed_like_{$id_post}", $request->request->get('_token'))) {
    return new JsonResponse(['success' => false, 'message' => 'CSRF token invalid'], 403);
}

// Comment route (line 211)
if (!$this->isCsrfTokenValid("feed_comment_{$id_post}", $request->request->get('_token'))) {
    return new JsonResponse(['success' => false, 'message' => 'CSRF token invalid'], 403);
}

// Participate route (line 263)
if (!$this->isCsrfTokenValid("feed_participate_{$id_post}", $request->request->get('_token'))) {
    return new JsonResponse(['success' => false, 'message' => 'CSRF token invalid'], 403);
}
```

All routes return JSON responses correctly for AJAX handling.

---

## Key Fixes Summary

| Issue | Root Cause | Solution | Status |
|-------|-----------|----------|--------|
| CSRF "invalid" error on like | CSRF token not extracted/sent | Stored token in `data-csrf`, sent via `URLSearchParams` | ✅ Fixed |
| CSRF "invalid" error on participate | CSRF token not extracted/sent | Stored token in `data-csrf`, sent via `URLSearchParams` | ✅ Fixed |
| Like button losing state | No visual feedback mechanism | Added icon toggle + color change + animation | ✅ Enhanced |
| Participate button not updating | No button state toggle | Added btn-primary class toggle + text update | ✅ Enhanced |
| Comment section not expanding | Comment button had no handler | Added toggle handler with auto-focus | ✅ Fixed |
| New comments hidden | No preview update | Prepends new comments to preview list | ✅ Fixed |
| Counts not updating | Stats row not referenced | Added IDs to stat spans, dynamically updated via JavaScript | ✅ Fixed |

---

## Testing Checklist

- [ ] Click Like → ❤️ icon appears, count increments, no CSRF error
- [ ] Click Like again → ❤️ disappears, count decrements, no page reload
- [ ] Like animation plays smoothly
- [ ] Click Commenter → section expands, input auto-focuses
- [ ] Type and submit comment → count updates, new comment appears at top of preview
- [ ] Comment section stays expanded after posting
- [ ] Click Participer → button becomes primary, text changes, count updates
- [ ] Click Annuler → button reverts, text changes, count updates
- [ ] Event capacity full → participate shows error alert (no page reload)
- [ ] All counts in stats row update in real-time
- [ ] Page scroll position preserved during all actions
- [ ] No CSRF token errors in browser console

---

## Files Modified

1. **templates/employe/feed.html.twig** (Complete replacement)
   - Added data-csrf attributes to Like and Participate buttons
   - Added data-action identifiers for button types
   - Added IDs to stat spans for dynamic updates
   - Rewrote JavaScript with proper CSRF handling and URLSearchParams
   - Added CSS animation for like button
   - Replaced FormData with URLSearchParams for consistent form encoding

2. **src/Controller/Employe/FeedController.php** (No changes - already correct)
   - All CSRF validation already in place
   - All JSON responses properly formatted
   - All routes return correct data for AJAX handling

---

## Browser Requirements

- ES6 JavaScript support (async/await)
- Fetch API
- ES6 URLSearchParams
- Modern browser (Chrome 55+, Firefox 52+, Safari 11+, Edge 15+)

All requirements met for modern development environment.
