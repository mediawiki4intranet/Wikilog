<?php
/**
 * Internationalisation file for extension Wikilog.
 *
 * @file
 * @ingroup Extensions
 */

$messages = array();

$messages['en'] = array(
	'wikilog-comments' => 'Comments',

	'wikilog-comment-permalink' => '{{#if:$3|$1 at $2 ($3)|<b>$1 at $2 ($3) [unread]</b>}}',
	'wikilog-comment-note-item' => 'posted to $1',
	'wikilog-comment-note-edited' => 'last edited on $1 at $2',
	'wikilog-comment-anonsig' => '$3 (anonymous)',
	'wikilog-comment-pending' => 'This comment is awaiting approval.',
	'wikilog-comment-deleted' => 'This comment was deleted.',
	'wikilog-comment-omitted-x' => 'Comment omitted (#$1).',
	'wikilog-comment-autosumm' => 'New comment by $1: $2',
	'wikilog-reply-to-comment' => 'Post a reply to this comment',
	'wikilog-login-to-comment' => 'Please [[Special:UserLogin|login]] to comment.',
	'wikilog-comment-page' => "Go to this comment's page",
	'wikilog-comment-edit' => 'Edit this comment',
	'wikilog-comment-delete' => 'Delete this comment',
	'wikilog-comment-history' => 'View comment history',
	'wikilog-comment-approve' => 'Approve this comment (immediate action)',
	'wikilog-comment-reject' => 'Reject this comment (immediate action)',
	'wikilog-newtalk-text' => '<!-- blank page created by Comments -->',
	'wikilog-newtalk-summary' => 'created automatically by Comments',

    'wikilog-do-subscribe' => '<p>You are not yet subscribed to all comments to this post. <a href="$1">Subscribe</a>.</p><hr />',
	'wikilog-do-unsubscribe' => '<p>You are <b>subscribed</b> to all comments to this post. <a href="$1">Unsubscribe</a>.</p><hr />',
    'wikilog-subscribed-title-yes' => 'Subscribed to comments',
	'wikilog-subscribed-title-no' => 'Unsubscribed from comments',
	'wikilog-subscribed-text-yes' => 'You are now subcribed to all comments to Wikilog entry [[$1]] by e-mail.',
	'wikilog-subscribed-text-no' => 'You are now unsubcribed from comments to Wikilog entry [[$1]] except the answers to your ones.',
	'wikilog-subscribed-as-author' => '<p>You will receive all comments to this entry by e-mail, because you are the author.</p><hr />',

   	'wikilog-comment-feed-title1' => 'Comment by $2 (#$1)',
	'wikilog-comment-feed-title2' => 'Comment by $2 to $3 (#$1)',
	'wikilog-comment-feed-description' => 'Read the most recent comments in this feed.',

    'wikilog-comment-email-subject' => '[Wikilog] $2 - A new comment to {{SUBPAGENAME}}',
	'wikilog-comment-email-body' =>
'A new reply {{#if:$6|was added by [[{{ns:User}}:$2|$2]] to the following [[{{ns:User}}:$6|$6]]\'s
comment to the post <html><a href="$3"></html>{{SUBPAGENAME}}<html></a></html>:

<div style="border-style: solid; border-color: black; border-width: 0 0 0 3px; padding-left: 8px;">
{{:$5}}
</div>

The reply was:|to <html><a href="$3"></html>{{SUBPAGENAME}}<html></a></html> was added by [[{{ns:User}}:$2|$2]]:}}

<div style="border-style: solid; border-color: black; border-width: 0 0 0 3px; padding-left: 8px;">
{{:$1}}
</div>

Available actions:

* [[{{TALKPAGENAME}}#$4|Read the whole discussion]] of the post {{SUBPAGENAME}}.
* <html><a href="$3"></html>Read the post {{SUBPAGENAME}}<html></a></html>.
* [[$1|Reply to this comment]] and/or read discussion thread.',
	'wikilog-comment-email-unsubscribe' => '<p><a href="$2">Unsubscribe</a> from comments to $1.</p>',
    
   	'wikilog-title-comments' => 'Comments - $1', # $1 = article title

    'wikilog-comment-is-empty' => 'Posted comment is blank.',
	'wikilog-comment-too-long' => 'Posted comment is too long.',
	'wikilog-comment-invalid-name' => 'Provided name is invalid.',
	'wikilog-post-comment' => 'Post a new comment',
	'wikilog-form-comment' => 'Comment:',

	'wikilog-subscription-comments' => 'Subscriptions to comments:',
	'wikilog-subscription-comments-empty' => '<strong>You are not subscribed to any comments</strong>',
	'wikilog-subscription-header-comments' => 'Article/blog title you are subscribed to comments',
	'wikilog-subscription-comment-unsubscribed-article' => 'Your subscription to the comments to article <strong>$1</strong> is canceled.',
	'wikilog-subscription-comment-subscription' => 'You can subscribe to comments at discussion page: <a href="$1">$2</a>.',

);

$messages['ru'] = array(
	'wikilog-comments' => 'Комментарии',
    
    'wikilog-comment-permalink' => '{{#if:$3|$1 в $2|<b>$1 в $2 (не прочитан)</b>}}',
	'wikilog-comment-note-item' => 'размещён в $1',
	'wikilog-comment-note-edited' => 'последняя правка $1 в $2',
	'wikilog-comment-anonsig' => '$3 (анонимно)',
	'wikilog-comment-pending' => 'Этот комментарий ожидает утверждения.',
	'wikilog-comment-deleted' => 'Этот комментарий был удалён.',
	'wikilog-comment-omitted-x' => 'Комментатор не указан (#$1).',
	'wikilog-comment-autosumm' => 'Новый комментарий от $1: $2',
	'wikilog-reply-to-comment' => 'Ответить на этот комментарий',
	'wikilog-login-to-comment' => '[[Special:UserLogin|Войдите]], чтобы комментировать.',
	'wikilog-comment-page' => 'Перейти на страницу этого комментария',
	'wikilog-comment-edit' => 'Изменить этот комментарий',
	'wikilog-comment-delete' => 'Удалить этот комментарий',
	'wikilog-comment-history' => 'Посмотреть историю комментария',
	'wikilog-comment-approve' => 'Утвердить этот комментарий (незамедлительное действие)',
	'wikilog-comment-reject' => 'Отклонить этот комментарий (незамедлительное действие)',
	'wikilog-subscribe' => 'Подписаться на комментарии к этой записи',
	'wikilog-do-subscribe' => '<p>Вы ещё не подписаны на все комментарии к этой записи. <a href="$1">Подписаться</a>.</p><hr />',
	'wikilog-do-unsubscribe' => '<p>Вы <b>подписаны</b> на все комментарии к этой записи. <a href="$1">Отписаться</a>.</p><hr />',
	'wikilog-newtalk-text' => '<!-- пустая страница создана комментариями -->',
	'wikilog-newtalk-summary' => 'создана автоматически комментариями',

	'wikilog-do-subscribe' => '<p>Вы ещё не подписаны на все комментарии к этой записи. <a href="$1">Подписаться</a>.</p><hr />',
	'wikilog-do-unsubscribe' => '<p>Вы <b>подписаны</b> на все комментарии к этой записи. <a href="$1">Отписаться</a>.</p><hr />',
   	'wikilog-subscribed-title-yes' => 'Вы подписаны на комментарии',
	'wikilog-subscribed-title-no' => 'Вы отписаны от комментариев',
	'wikilog-subscribed-text-yes' => 'Теперь вы подписаны по e-mail на все комментарии к записи [[$1]].',
	'wikilog-subscribed-text-no' => 'Теперь вы отписаны от комментариев к записи [[$1]], кроме ответов на лично ваши.',
	'wikilog-subscribed-as-author' => '<p>Вы будете получать все комментарии к этой записи по e-mail, потому что вы её автор.</p><hr />',

	'wikilog-comment-feed-title1' => 'Комментарии от $2 (#$1)',
	'wikilog-comment-feed-title2' => 'Комментарий от $2 к $3 (#$1)',
	'wikilog-comment-feed-description' => 'Читать последние комментарии на этом канале.',

	'wikilog-comment-email-subject' => '[Wikilog] $2 - Новый комментарий к {{SUBPAGENAME}}',
	'wikilog-comment-email-body' =>
'Пользователь [[{{ns:User}}:$2|$2]] ответил на {{#if:$6|комментарий, оставленный
[[{{ns:User}}:$6|$6]] к записи <html><a href="$3"></html>{{SUBPAGENAME}}<html></a></html>:

<div style="border-style: solid; border-color: black; border-width: 0 0 0 3px; padding-left: 8px;">
{{:$5}}
</div>

Ответ был таким:|запись <html><a href="$3"></html>{{SUBPAGENAME}}<html></a></html>:}}

<div style="border-style: solid; border-color: black; border-width: 0 0 0 3px; padding-left: 8px;">
{{:$1}}
</div>

Доступные действия:

* [[{{TALKPAGENAME}}#$4|Просмотреть полное обсуждение]] записи {{SUBPAGENAME}}.
* <html><a href="$3"></html>Прочитать запись {{SUBPAGENAME}}<html></a></html>.
* [[$1|Ответить на этот комментарий]] и/или просмотреть ветвь обсуждения.',
	'wikilog-comment-email-unsubscribe' => '<p><a href="$2">Отписаться</a> от комментариев к записи $1.</p>',

	'wikilog-title-comments' => 'Комментарии — $1',

	'wikilog-comment-is-empty' => 'Отправленный комментарий пуст.',
	'wikilog-comment-too-long' => 'Отправленный комментарий слишком длинный.',
	'wikilog-comment-invalid-name' => 'Указанная имя является некорректным.',
	'wikilog-post-comment' => 'Написать новый комментарий',
	'wikilog-form-comment' => 'Комментарий:',

    'wikilog-subscription-comments' => 'Подписки на комментарии:',
	'wikilog-subscription-comments-empty' => '<strong>У вас нет e-mail подписок на комментарии</strong>',
	'wikilog-subscription-header-comments' => 'Название статьи или блога, на комментарии которых вы подписаны',
	'wikilog-subscription-comment-unsubscribed-article' => 'Вы отменили e-mail подписку на комментарии к статье <strong>$1</strong>.',
	'wikilog-subscription-comment-subscription' => 'Подписаться на комментарии можно на странице обсуждения: <a href="$1">$2</a>.',

);

