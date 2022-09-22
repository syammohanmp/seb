# Scheduled Entity Block (seb)

Scheduled Entity Block module provides you to display content during the scheduled time. Scheduled block allows you to select existing block or entity.

Scheduled block with entity, you can select the view mode that the entity will render in. 

Different modes of schedulers available like Start/End date wise, month, week, specific day, etc


## Typical use cases:

* You have a home page region which typically displays banners. For next week only you want to show a banner ad.

  * Simply place scheduled_content_display block and select the banner block then select the start and end date/times for it to show.

* You have a block to display in the sidebar every Wednesday.

  * Place scheduled_content_display block and select the content which you want to display, then select the scheduled field as Day->Wednesday.

* You have a block to display for a special day.

  * Place scheduled_content_display block and select the content which you want to display, then set start and end time.

## How to install
You can install seb module through composer.


`composer install drupal/seb`