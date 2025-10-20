This extension provides an API and frontend (in progress) through which users can manage private lists
of articles, such as bookmarks or "Read Later" lists.

The extension is designed for use on wiki farms; it stores articles as domain name + title pairs, so that the list service can be setup on a single central wiki.

# Getting started

* Make sure you have installed MediaWiki
* Install ReadingLists using the instructions at https://www.mediawiki.org/wiki/Extension:ReadingLists
* Install BetaFeatures using the instructions at https://www.mediawiki.org/wiki/Extension:BetaFeatures
* Configure the extension locally by editing LocalSettings.php to include the following:
```
$wgReadingListBetaFeature = true;
$wgReadingListsDeveloperMode = true;
# Enable toolbar (sorting etc.) on Special:ReadingLists.
$wgReadingListsEnableSpecialPageToolbar = true;
$wgReadingListsWebAuthenticatedPreviews = true;
$wgReadingListAndroidAppDownloadLink = "https://play.google.com/store/apps/details?id=org.wikipedia&referrer=utm_source%3DreadingListsShare";
$wgReadingListiOSAppDownloadLink = "https://itunes.apple.com/app/apple-store/id324715238?pt=208305&ct=readingListsShare";
```
* Navigate to your MediaWiki installation and login to your account.
* Visit the "Beta" link in your user menu (or go to Preferences -> Beta features) and enable the "Reading lists" feature.
* Navigate to any regular page in your MediaWiki installation, and you should now see a "Bookmark" option in the "More" menu. You should also see a "Reading lists" link under your user menu, which will take you to the Special:ReadingLists page that's used for managing your lists.
