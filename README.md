# Silverstripe Basic Auth

## How to Use

The `DataObjectExtension` is automatically applied to `SiteTree`

Otherwise just add the extension to any object that you need to have Basic Auth applied to, if the class has the Hierarchy extension then `InheritBasicAuth` becomes an option for all child objects

## If using on SiteTree (Page)

Add the following code at the top of `Page_Controller::init()`

```php
$this->data()->doVerifyBasicAuth($this->request);
```

This will trigger the checks
