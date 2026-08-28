<?php

/**
 * Bundled Font Awesome icon metadata (LPP-002): a curated subset of Font
 * Awesome 6 Free's solid-style icon set, used by the admin icon picker
 * (TinyMCE/EasyMDE) and its search cache — not the full upstream catalog,
 * which this plugin never vendors (see FontAwesomeService::cssUrls()'s own
 * docblock on staying CDN/self-hosted-only). Curated rather than complete:
 * a few hundred of the most commonly used, unambiguous icon names across
 * everyday categories, so the picker is genuinely useful for the [icon]
 * shortcode without shipping (or having to keep in sync with) Font
 * Awesome's entire multi-thousand-icon library and its own versioned
 * metadata format.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.9.0
 */

declare(strict_types=1);

/**
 * @return array<int, array{name: string, label: string, category: string, keywords: array<int, string>}>
 */
return [
    // Arrows & Navigation
    ['name' => 'arrow-up', 'label' => 'Arrow Up', 'category' => 'Arrows', 'keywords' => ['up', 'top', 'direction']],
    ['name' => 'arrow-down', 'label' => 'Arrow Down', 'category' => 'Arrows', 'keywords' => ['down', 'bottom', 'direction']],
    ['name' => 'arrow-left', 'label' => 'Arrow Left', 'category' => 'Arrows', 'keywords' => ['left', 'back', 'direction']],
    ['name' => 'arrow-right', 'label' => 'Arrow Right', 'category' => 'Arrows', 'keywords' => ['right', 'forward', 'next', 'direction']],
    ['name' => 'arrow-up-right-from-square', 'label' => 'External Link', 'category' => 'Arrows', 'keywords' => ['external', 'link', 'new tab', 'open']],
    ['name' => 'arrows-rotate', 'label' => 'Refresh', 'category' => 'Arrows', 'keywords' => ['refresh', 'reload', 'sync', 'rotate']],
    ['name' => 'rotate-left', 'label' => 'Undo', 'category' => 'Arrows', 'keywords' => ['undo', 'back', 'reverse']],
    ['name' => 'rotate-right', 'label' => 'Redo', 'category' => 'Arrows', 'keywords' => ['redo', 'forward']],
    ['name' => 'chevron-up', 'label' => 'Chevron Up', 'category' => 'Arrows', 'keywords' => ['up', 'collapse', 'expand']],
    ['name' => 'chevron-down', 'label' => 'Chevron Down', 'category' => 'Arrows', 'keywords' => ['down', 'collapse', 'expand', 'dropdown']],
    ['name' => 'chevron-left', 'label' => 'Chevron Left', 'category' => 'Arrows', 'keywords' => ['left', 'back', 'previous']],
    ['name' => 'chevron-right', 'label' => 'Chevron Right', 'category' => 'Arrows', 'keywords' => ['right', 'next', 'forward']],
    ['name' => 'angle-up', 'label' => 'Angle Up', 'category' => 'Arrows', 'keywords' => ['up', 'scroll']],
    ['name' => 'angle-down', 'label' => 'Angle Down', 'category' => 'Arrows', 'keywords' => ['down', 'scroll']],
    ['name' => 'up-down', 'label' => 'Vertical Arrows', 'category' => 'Arrows', 'keywords' => ['resize', 'expand', 'vertical']],
    ['name' => 'left-right', 'label' => 'Horizontal Arrows', 'category' => 'Arrows', 'keywords' => ['resize', 'expand', 'horizontal']],

    // Communication
    ['name' => 'envelope', 'label' => 'Envelope', 'category' => 'Communication', 'keywords' => ['email', 'mail', 'message', 'contact']],
    ['name' => 'envelope-open', 'label' => 'Envelope Open', 'category' => 'Communication', 'keywords' => ['email', 'mail', 'read']],
    ['name' => 'phone', 'label' => 'Phone', 'category' => 'Communication', 'keywords' => ['call', 'telephone', 'contact']],
    ['name' => 'comment', 'label' => 'Comment', 'category' => 'Communication', 'keywords' => ['chat', 'message', 'speech', 'bubble']],
    ['name' => 'comments', 'label' => 'Comments', 'category' => 'Communication', 'keywords' => ['chat', 'messages', 'discussion']],
    ['name' => 'paper-plane', 'label' => 'Send', 'category' => 'Communication', 'keywords' => ['send', 'message', 'submit']],
    ['name' => 'bell', 'label' => 'Bell', 'category' => 'Communication', 'keywords' => ['notification', 'alert', 'reminder']],
    ['name' => 'bullhorn', 'label' => 'Bullhorn', 'category' => 'Communication', 'keywords' => ['announcement', 'megaphone', 'promote']],
    ['name' => 'rss', 'label' => 'RSS', 'category' => 'Communication', 'keywords' => ['feed', 'subscribe', 'syndication']],
    ['name' => 'at', 'label' => 'At Sign', 'category' => 'Communication', 'keywords' => ['email', 'mention', 'username']],

    // Media
    ['name' => 'camera', 'label' => 'Camera', 'category' => 'Media', 'keywords' => ['photo', 'picture', 'image']],
    ['name' => 'image', 'label' => 'Image', 'category' => 'Media', 'keywords' => ['picture', 'photo', 'gallery']],
    ['name' => 'images', 'label' => 'Images', 'category' => 'Media', 'keywords' => ['gallery', 'pictures', 'photos']],
    ['name' => 'video', 'label' => 'Video', 'category' => 'Media', 'keywords' => ['camera', 'movie', 'film']],
    ['name' => 'film', 'label' => 'Film', 'category' => 'Media', 'keywords' => ['movie', 'video', 'reel']],
    ['name' => 'music', 'label' => 'Music', 'category' => 'Media', 'keywords' => ['song', 'audio', 'note']],
    ['name' => 'microphone', 'label' => 'Microphone', 'category' => 'Media', 'keywords' => ['audio', 'record', 'podcast']],
    ['name' => 'headphones', 'label' => 'Headphones', 'category' => 'Media', 'keywords' => ['audio', 'music', 'listen']],
    ['name' => 'play', 'label' => 'Play', 'category' => 'Media', 'keywords' => ['start', 'video', 'audio']],
    ['name' => 'pause', 'label' => 'Pause', 'category' => 'Media', 'keywords' => ['stop', 'video', 'audio']],
    ['name' => 'volume-high', 'label' => 'Volume', 'category' => 'Media', 'keywords' => ['sound', 'audio', 'speaker']],
    ['name' => 'volume-xmark', 'label' => 'Mute', 'category' => 'Media', 'keywords' => ['sound', 'silent', 'off']],

    // Files & Documents
    ['name' => 'file', 'label' => 'File', 'category' => 'Files', 'keywords' => ['document', 'page']],
    ['name' => 'file-lines', 'label' => 'Document', 'category' => 'Files', 'keywords' => ['file', 'text', 'page', 'article']],
    ['name' => 'file-pdf', 'label' => 'PDF File', 'category' => 'Files', 'keywords' => ['pdf', 'document']],
    ['name' => 'file-word', 'label' => 'Word File', 'category' => 'Files', 'keywords' => ['document', 'doc']],
    ['name' => 'file-image', 'label' => 'Image File', 'category' => 'Files', 'keywords' => ['picture', 'photo']],
    ['name' => 'file-zipper', 'label' => 'ZIP File', 'category' => 'Files', 'keywords' => ['zip', 'archive', 'compressed']],
    ['name' => 'folder', 'label' => 'Folder', 'category' => 'Files', 'keywords' => ['directory', 'files']],
    ['name' => 'folder-open', 'label' => 'Folder Open', 'category' => 'Files', 'keywords' => ['directory', 'files', 'open']],
    ['name' => 'download', 'label' => 'Download', 'category' => 'Files', 'keywords' => ['save', 'export', 'get']],
    ['name' => 'upload', 'label' => 'Upload', 'category' => 'Files', 'keywords' => ['import', 'send', 'add']],
    ['name' => 'print', 'label' => 'Print', 'category' => 'Files', 'keywords' => ['printer', 'document']],
    ['name' => 'copy', 'label' => 'Copy', 'category' => 'Files', 'keywords' => ['duplicate', 'clipboard']],
    ['name' => 'paste', 'label' => 'Paste', 'category' => 'Files', 'keywords' => ['clipboard', 'copy']],
    ['name' => 'floppy-disk', 'label' => 'Save', 'category' => 'Files', 'keywords' => ['save', 'disk', 'diskette']],
    ['name' => 'paperclip', 'label' => 'Paperclip', 'category' => 'Files', 'keywords' => ['attachment', 'attach']],

    // Users & People
    ['name' => 'user', 'label' => 'User', 'category' => 'Users', 'keywords' => ['person', 'account', 'profile']],
    ['name' => 'user-plus', 'label' => 'Add User', 'category' => 'Users', 'keywords' => ['person', 'register', 'signup']],
    ['name' => 'user-group', 'label' => 'User Group', 'category' => 'Users', 'keywords' => ['team', 'people', 'group']],
    ['name' => 'users', 'label' => 'Users', 'category' => 'Users', 'keywords' => ['people', 'team', 'group']],
    ['name' => 'user-secret', 'label' => 'Anonymous', 'category' => 'Users', 'keywords' => ['incognito', 'private', 'secret']],
    ['name' => 'address-card', 'label' => 'Contact Card', 'category' => 'Users', 'keywords' => ['profile', 'contact', 'id']],
    ['name' => 'id-card', 'label' => 'ID Card', 'category' => 'Users', 'keywords' => ['identity', 'badge']],

    // Commerce & Money
    ['name' => 'cart-shopping', 'label' => 'Shopping Cart', 'category' => 'Commerce', 'keywords' => ['cart', 'store', 'buy']],
    ['name' => 'bag-shopping', 'label' => 'Shopping Bag', 'category' => 'Commerce', 'keywords' => ['bag', 'store', 'buy']],
    ['name' => 'tag', 'label' => 'Tag', 'category' => 'Commerce', 'keywords' => ['price', 'label', 'sale']],
    ['name' => 'tags', 'label' => 'Tags', 'category' => 'Commerce', 'keywords' => ['price', 'labels', 'sale']],
    ['name' => 'credit-card', 'label' => 'Credit Card', 'category' => 'Commerce', 'keywords' => ['payment', 'card', 'money']],
    ['name' => 'money-bill', 'label' => 'Money', 'category' => 'Commerce', 'keywords' => ['cash', 'bill', 'currency']],
    ['name' => 'coins', 'label' => 'Coins', 'category' => 'Commerce', 'keywords' => ['money', 'cash', 'currency']],
    ['name' => 'receipt', 'label' => 'Receipt', 'category' => 'Commerce', 'keywords' => ['invoice', 'bill']],
    ['name' => 'gift', 'label' => 'Gift', 'category' => 'Commerce', 'keywords' => ['present', 'box']],
    ['name' => 'percent', 'label' => 'Percent', 'category' => 'Commerce', 'keywords' => ['discount', 'sale']],

    // Weather
    ['name' => 'sun', 'label' => 'Sun', 'category' => 'Weather', 'keywords' => ['sunny', 'day', 'bright']],
    ['name' => 'moon', 'label' => 'Moon', 'category' => 'Weather', 'keywords' => ['night', 'dark']],
    ['name' => 'cloud', 'label' => 'Cloud', 'category' => 'Weather', 'keywords' => ['weather', 'sky']],
    ['name' => 'cloud-rain', 'label' => 'Rain', 'category' => 'Weather', 'keywords' => ['rainy', 'weather', 'storm']],
    ['name' => 'snowflake', 'label' => 'Snowflake', 'category' => 'Weather', 'keywords' => ['snow', 'winter', 'cold']],
    ['name' => 'bolt', 'label' => 'Lightning', 'category' => 'Weather', 'keywords' => ['thunder', 'storm', 'flash', 'energy']],
    ['name' => 'wind', 'label' => 'Wind', 'category' => 'Weather', 'keywords' => ['breeze', 'weather']],
    ['name' => 'umbrella', 'label' => 'Umbrella', 'category' => 'Weather', 'keywords' => ['rain', 'protection']],

    // Devices & Technology
    ['name' => 'laptop', 'label' => 'Laptop', 'category' => 'Devices', 'keywords' => ['computer', 'notebook']],
    ['name' => 'desktop', 'label' => 'Desktop', 'category' => 'Devices', 'keywords' => ['computer', 'monitor']],
    ['name' => 'mobile-screen', 'label' => 'Mobile Phone', 'category' => 'Devices', 'keywords' => ['phone', 'smartphone', 'cell']],
    ['name' => 'tablet', 'label' => 'Tablet', 'category' => 'Devices', 'keywords' => ['ipad', 'device']],
    ['name' => 'keyboard', 'label' => 'Keyboard', 'category' => 'Devices', 'keywords' => ['type', 'input']],
    ['name' => 'wifi', 'label' => 'Wi-Fi', 'category' => 'Devices', 'keywords' => ['wireless', 'internet', 'network']],
    ['name' => 'battery-full', 'label' => 'Battery', 'category' => 'Devices', 'keywords' => ['power', 'charge']],
    ['name' => 'plug', 'label' => 'Plug', 'category' => 'Devices', 'keywords' => ['power', 'charge', 'connect']],
    ['name' => 'database', 'label' => 'Database', 'category' => 'Devices', 'keywords' => ['storage', 'server', 'data']],
    ['name' => 'server', 'label' => 'Server', 'category' => 'Devices', 'keywords' => ['hosting', 'computer', 'data']],
    ['name' => 'code', 'label' => 'Code', 'category' => 'Devices', 'keywords' => ['programming', 'developer', 'html']],
    ['name' => 'terminal', 'label' => 'Terminal', 'category' => 'Devices', 'keywords' => ['console', 'command line', 'shell']],

    // Editing & Actions
    ['name' => 'pen', 'label' => 'Pen', 'category' => 'Editing', 'keywords' => ['edit', 'write']],
    ['name' => 'pen-to-square', 'label' => 'Edit', 'category' => 'Editing', 'keywords' => ['edit', 'pencil', 'modify']],
    ['name' => 'trash', 'label' => 'Trash', 'category' => 'Editing', 'keywords' => ['delete', 'remove', 'bin']],
    ['name' => 'plus', 'label' => 'Plus', 'category' => 'Editing', 'keywords' => ['add', 'new', 'create']],
    ['name' => 'minus', 'label' => 'Minus', 'category' => 'Editing', 'keywords' => ['remove', 'subtract']],
    ['name' => 'xmark', 'label' => 'Close', 'category' => 'Editing', 'keywords' => ['close', 'cancel', 'x', 'remove']],
    ['name' => 'check', 'label' => 'Check', 'category' => 'Editing', 'keywords' => ['done', 'success', 'complete', 'tick']],
    ['name' => 'check-double', 'label' => 'Double Check', 'category' => 'Editing', 'keywords' => ['done', 'verified']],
    ['name' => 'magnifying-glass', 'label' => 'Search', 'category' => 'Editing', 'keywords' => ['find', 'search', 'zoom']],
    ['name' => 'filter', 'label' => 'Filter', 'category' => 'Editing', 'keywords' => ['sort', 'refine']],
    ['name' => 'gear', 'label' => 'Settings', 'category' => 'Editing', 'keywords' => ['gear', 'cog', 'preferences', 'config']],
    ['name' => 'sliders', 'label' => 'Sliders', 'category' => 'Editing', 'keywords' => ['settings', 'adjust', 'controls']],
    ['name' => 'eye', 'label' => 'View', 'category' => 'Editing', 'keywords' => ['show', 'visible', 'watch']],
    ['name' => 'eye-slash', 'label' => 'Hide', 'category' => 'Editing', 'keywords' => ['invisible', 'private']],
    ['name' => 'link', 'label' => 'Link', 'category' => 'Editing', 'keywords' => ['url', 'chain', 'connect']],
    ['name' => 'thumbtack', 'label' => 'Pin', 'category' => 'Editing', 'keywords' => ['sticky', 'attach', 'pinned']],

    // Security
    ['name' => 'lock', 'label' => 'Lock', 'category' => 'Security', 'keywords' => ['secure', 'private', 'password']],
    ['name' => 'lock-open', 'label' => 'Unlock', 'category' => 'Security', 'keywords' => ['open', 'unsecure']],
    ['name' => 'shield', 'label' => 'Shield', 'category' => 'Security', 'keywords' => ['protection', 'security', 'defense']],
    ['name' => 'shield-halved', 'label' => 'Shield Check', 'category' => 'Security', 'keywords' => ['protection', 'verified', 'secure']],
    ['name' => 'key', 'label' => 'Key', 'category' => 'Security', 'keywords' => ['password', 'unlock', 'access']],
    ['name' => 'fingerprint', 'label' => 'Fingerprint', 'category' => 'Security', 'keywords' => ['biometric', 'identity', 'security']],
    ['name' => 'triangle-exclamation', 'label' => 'Warning', 'category' => 'Security', 'keywords' => ['alert', 'caution', 'error']],
    ['name' => 'circle-exclamation', 'label' => 'Alert Circle', 'category' => 'Security', 'keywords' => ['warning', 'info', 'error']],

    // Transportation
    ['name' => 'car', 'label' => 'Car', 'category' => 'Transportation', 'keywords' => ['vehicle', 'auto', 'drive']],
    ['name' => 'plane', 'label' => 'Plane', 'category' => 'Transportation', 'keywords' => ['flight', 'travel', 'airplane']],
    ['name' => 'train', 'label' => 'Train', 'category' => 'Transportation', 'keywords' => ['railway', 'rail', 'transit']],
    ['name' => 'ship', 'label' => 'Ship', 'category' => 'Transportation', 'keywords' => ['boat', 'sea', 'travel']],
    ['name' => 'bicycle', 'label' => 'Bicycle', 'category' => 'Transportation', 'keywords' => ['bike', 'cycling']],
    ['name' => 'bus', 'label' => 'Bus', 'category' => 'Transportation', 'keywords' => ['vehicle', 'transit']],
    ['name' => 'location-dot', 'label' => 'Location Pin', 'category' => 'Transportation', 'keywords' => ['map', 'pin', 'place', 'address']],
    ['name' => 'map', 'label' => 'Map', 'category' => 'Transportation', 'keywords' => ['location', 'directions']],
    ['name' => 'compass', 'label' => 'Compass', 'category' => 'Transportation', 'keywords' => ['navigation', 'direction']],
    ['name' => 'road', 'label' => 'Road', 'category' => 'Transportation', 'keywords' => ['street', 'path']],

    // Nature & Objects
    ['name' => 'star', 'label' => 'Star', 'category' => 'Objects', 'keywords' => ['favorite', 'rating', 'bookmark']],
    ['name' => 'heart', 'label' => 'Heart', 'category' => 'Objects', 'keywords' => ['love', 'like', 'favorite']],
    ['name' => 'house', 'label' => 'House', 'category' => 'Objects', 'keywords' => ['home', 'building']],
    ['name' => 'book', 'label' => 'Book', 'category' => 'Objects', 'keywords' => ['read', 'library', 'novel']],
    ['name' => 'bookmark', 'label' => 'Bookmark', 'category' => 'Objects', 'keywords' => ['save', 'flag', 'mark']],
    ['name' => 'calendar', 'label' => 'Calendar', 'category' => 'Objects', 'keywords' => ['date', 'schedule', 'event']],
    ['name' => 'clock', 'label' => 'Clock', 'category' => 'Objects', 'keywords' => ['time', 'watch']],
    ['name' => 'globe', 'label' => 'Globe', 'category' => 'Objects', 'keywords' => ['world', 'internet', 'earth']],
    ['name' => 'leaf', 'label' => 'Leaf', 'category' => 'Objects', 'keywords' => ['nature', 'plant', 'eco']],
    ['name' => 'tree', 'label' => 'Tree', 'category' => 'Objects', 'keywords' => ['nature', 'forest', 'plant']],
    ['name' => 'paw', 'label' => 'Paw', 'category' => 'Objects', 'keywords' => ['animal', 'pet']],
    ['name' => 'trophy', 'label' => 'Trophy', 'category' => 'Objects', 'keywords' => ['award', 'winner', 'achievement']],
    ['name' => 'lightbulb', 'label' => 'Lightbulb', 'category' => 'Objects', 'keywords' => ['idea', 'light', 'tip']],
    ['name' => 'flag', 'label' => 'Flag', 'category' => 'Objects', 'keywords' => ['marker', 'report', 'country']],
    ['name' => 'coffee', 'label' => 'Coffee', 'category' => 'Objects', 'keywords' => ['drink', 'cup', 'cafe']],
    ['name' => 'utensils', 'label' => 'Utensils', 'category' => 'Objects', 'keywords' => ['food', 'fork', 'restaurant']],
    ['name' => 'gamepad', 'label' => 'Gamepad', 'category' => 'Objects', 'keywords' => ['game', 'controller', 'video game']],
    ['name' => 'palette', 'label' => 'Palette', 'category' => 'Objects', 'keywords' => ['art', 'design', 'color']],
    ['name' => 'graduation-cap', 'label' => 'Graduation Cap', 'category' => 'Objects', 'keywords' => ['education', 'school', 'degree']],
    ['name' => 'briefcase', 'label' => 'Briefcase', 'category' => 'Objects', 'keywords' => ['work', 'business', 'job']],

    // Social / Brands (fa-brands style)
    ['name' => 'facebook', 'label' => 'Facebook', 'category' => 'Social', 'keywords' => ['social', 'brand'], 'style' => 'brands'],
    ['name' => 'x-twitter', 'label' => 'X (Twitter)', 'category' => 'Social', 'keywords' => ['social', 'brand', 'twitter'], 'style' => 'brands'],
    ['name' => 'instagram', 'label' => 'Instagram', 'category' => 'Social', 'keywords' => ['social', 'brand', 'photo'], 'style' => 'brands'],
    ['name' => 'youtube', 'label' => 'YouTube', 'category' => 'Social', 'keywords' => ['social', 'brand', 'video'], 'style' => 'brands'],
    ['name' => 'github', 'label' => 'GitHub', 'category' => 'Social', 'keywords' => ['code', 'brand', 'developer'], 'style' => 'brands'],
    ['name' => 'discord', 'label' => 'Discord', 'category' => 'Social', 'keywords' => ['social', 'brand', 'chat'], 'style' => 'brands'],
    ['name' => 'tumblr', 'label' => 'Tumblr', 'category' => 'Social', 'keywords' => ['social', 'brand', 'blog'], 'style' => 'brands'],
    ['name' => 'pinterest', 'label' => 'Pinterest', 'category' => 'Social', 'keywords' => ['social', 'brand'], 'style' => 'brands'],
    ['name' => 'reddit', 'label' => 'Reddit', 'category' => 'Social', 'keywords' => ['social', 'brand', 'forum'], 'style' => 'brands'],
    ['name' => 'mastodon', 'label' => 'Mastodon', 'category' => 'Social', 'keywords' => ['social', 'brand', 'fediverse'], 'style' => 'brands'],
    ['name' => 'linkedin', 'label' => 'LinkedIn', 'category' => 'Social', 'keywords' => ['social', 'brand', 'professional'], 'style' => 'brands'],
    ['name' => 'patreon', 'label' => 'Patreon', 'category' => 'Social', 'keywords' => ['brand', 'support', 'donate'], 'style' => 'brands'],
];
