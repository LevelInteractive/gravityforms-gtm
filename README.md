# gravityforms-gtm

This is a lightweight plugin that changes the default confirmation behavior for forms that "redirect". The plugin will add an interstitial message and push a structured `dataLayer` event to Google Tag Manager containing any fields with a `name` property. 

> [!IMPORTANT]
> This plugin forces all forms to use the AJAX submission mode. This can pose issues for pages that embed the same form on a page twice. Just be aware to QA after installing.

## GTM `dataLayer` Event Structure

```javascript
// Assuming a submission from gravity form ID 4 on www.level.agency
{
  event: 'lvl.form_submit',
  form: {
    id: 'gravity-forms:0-4', // 0 is the site ID (will be 0 if not using multi-site)
    name: 'Contact Us', // The human readable form name
    provider: 'wordpress:www.level.agency',
    field_values: {
      entry_id: 343, // Reference ID to gravity form entry.
      // key: value pairs of all $entry data that can be tied to a field w/ a `name`
    }
  }
}
```

> [!NOTE]
> The `lvl.` namespace on the `event` value can be customized using the `lvl:gravityforms/gtm/event_namespace` filter documented in the Filters/Hooks section below.

### Exposing Field Values to DataLayer

Any field you wish to access in the `field_values` that isn't entry meta needs to have a value in the Advanced Field Settings "Allow field to be populated dynamically" `Parameter Name` setting. The value placed here will be used as the property name in the `form.field_values` object.

## Filters/Hooks

### `dataLayer` Event Namespace

By default, the event pushed to the `dataLayer` is prefixed with a `lvl.` namespace. This can be customized using the `lvl:gravityforms/gtm/event_namespace` filter:

```php
add_filter('lvl:gravityforms/gtm/event_namespace', function($namespace) {
  return 'custom_namespace';
}, 10, 2);
```

> [!TIP]
> We highly recommend using a `snake_case` formatted string when overriding/customizing the namespace. **Note** -- It is not possible to remove the namespace at this time. We may consider this in future releases.

### Redirect Delay

By default, the redirect delay is set at `1500` milliseconds. This can be configured using the `lvl:gravityforms/gtm/redirect_delay` filter:

```php
add_filter('lvl:gravityforms/gtm/redirect_delay', function($delay) {
  // Change the delay to 1000ms
  return 1000;
}, 10, 2);
```

> [!NOTE]
> We set a minimum redirect delay of `500` milliseconds to ensure there is at least a tiny speedbump to allow tags to execute before unloading the page. **Note** -- It is not possible to remove the redirect at this time. We may consider this in future releases.

### Interstitial Text

By default, the redirect interstitial content is set to `We are processing your submission. Please wait...`. This can be configured using the `lvl:gravityforms/gtm/redirect_interstitial_content` filter:

```php
add_filter('lvl:gravityforms/gtm/redirect_interstitial_content', function($content) {
  return "Please wait, we're processing your request.";
}, 10, 2);
```

### Spinner Color

The plugin uses CSS animations and variables for the spinner inside the interstitial. The default spinner color is `#000` but it can be customized via: `lvl:gravityforms/gtm/redirect_spinner_color`.

```php
add_filter('lvl:gravityforms/gtm/redirect_spinner_color', function($color) {
  // Changes spinner to LVL Pink
  return '#FD6EF8';
}, 10, 2);
```
