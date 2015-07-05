# phpBB 3.1 OO Posting API

This repository contains an API for simple creation and editing of posts and PMs in phpBB. It adds classes for post, topic, and pm. These classes can be integrated in an extension. The classes take care of all necessary parameter handling in the background so that only the forum_id, title and text of the new post must be set and the classes handle the rest. Any available bbcode can be used regularly in the text. For PMs, recipients can be set easily.

Warning: Development is still at an early stage and major bugs may still be present in this api. Currently, only the post and privmsg classes are ready for use, which means, no handling of attachments and no handling of polls. PMs can also not be loaded for editing currently.

## Installation

Add `gn36/phpbb-oo-posting-api` to your extensions composer dependencies and install using composer.

## Use

To create a new topic in the forum with forum_id = 1:

	$post = new \Gn36\OoPostingApi\post();
	
	$post->forum_id = 1;
	$post->post_subject = 'Subject of the new topic';
	$post->post_text = 'The text of the first post. [b]This is bold![/b]';
	
	// This is optional: Change the author id. Default is current user.
	$post->poster_id = ANONYMOUS; // Create a guest post (could be any user id)
	// This is only necessary for guests if you want a name to appear at the post
	$post->post_username = 'I am a guest';
	
	// Submit
	$post->submit();

To create a reply in the topic with topic_id = 1:

	$post = new \Gn36\OoPostingApi\post();
	
	$post->topic_id = 1;
	$post->post_subject = 'Subject of the new post';
	$post->post_text = 'The text of the reply. [i]This can also contain bbcodes.[/i]';
	
	$post->submit();
	
To edit a post with post_id = 1:

	$post = \Gn36\OoPostingApi\post::get(1);
	$post->post_text = 'This is the new post text';
	
	// This will also change the topic title if you edit the first post:
	$post->post_subject = 'This is the new post title';
	
	$post->submit();
	
To create a new pm to the user with user\_id = 2 and the group with group_id = 5:

	$pm = new \Gn36\OoPostingApi\privmsg();
	
	$pm->to(2); // Send to user with id 2
	$pm->to('g', 5); // Send to group with id 5
	
	$pm->message_subject = 'The subject of the pm';
	$pm->message_text = 'This is the text.
		Again [b]bold[/b] and [color=red]any other[/color] bbcode are allowed.';
	
	$pm->submit();

To reply to a pm with the message_id = 11:

	//reply_to(11, false) disables quoting the original text
	$pm = \Gn36\OoPostingApi\privmsg::reply_to(11);
	
	$pm->message_text .= 'The original message will be quoted and this text appears below.
		You could also replace the original text, but if you do so, consider passing a second parameter to	privmsg::reply_to, so it does not load the original text at all.';
	
	// Nothing else needed, recipients are set automatically:
	$pm->submit();

## Development

If you find a bug, please report it on https://github.com/gn36/phpbb-oo-posting-api

## Automated Testing

We will use automated unit tests including functional tests in the future to prevent regressions. Check out our travis build below:

master: [![Build Status](https://travis-ci.org/gn36/phpbb-oo-posting-api.png?branch=master)](http://travis-ci.org/gn36/phpbb-oo-posting-api)

## License

[GPLv2](license.txt)
