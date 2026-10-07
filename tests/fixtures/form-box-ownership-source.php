<?php
// phpcs:ignoreFile -- Neutral producer/consumer acceptance input, not a runtime plugin.

$css = 'body{margin:0;font:10px Arial;--caption:normal normal normal 38px/1.4em Arial}form,div,label,button,span{margin:0;padding:0;border:0;box-sizing:content-box}'
    . '.newsletter{width:280px}.rows{display:grid;grid-template-columns:100%;grid-template-rows:min-content 1fr}'
    . '#email-box{position:relative;left:20px;margin:32px 0 9px;width:240px;grid-area:1/1/2/2;place-self:start}'
    . '#email-box input{box-sizing:border-box;width:100%;height:40px;font:16px Arial}'
    . '#submit-box{position:relative;left:20px;margin:0 0 5px;width:240px;height:45px;grid-area:2/1/3/2;place-self:start;--caption:normal normal normal 16px/1.4em Arial}'
    . '#submit-box button{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;width:max-content;min-width:100%;box-sizing:border-box}'
    . '.caption{font:var(--caption)}'
    . '.choice-form{width:280px}.choice-grid{display:grid;grid-template-columns:100%;grid-template-rows:1fr;min-height:584px}'
    . '.choice{position:relative;left:10px;margin:10px 0;width:260px;height:18px;display:flex;align-items:center;gap:13px;place-self:start}'
    . '.choice input{box-sizing:border-box;margin:0;width:13px;height:13px}.choice span{font:11px/11px Arial}'
    . '.choice-form>button{height:45px;width:240px;font:16px Arial}'
    . '@media(min-width:768px){.newsletter{width:400px}#email-box,#submit-box{width:340px;left:30px}#email-box{margin-top:12px;margin-left:calc(50% - 190px)}#submit-box{--caption:normal normal normal 18px/1.4em Arial}.choice-form{display:flex;flex-direction:column;gap:4px}}';

$forms = array(
    'newsletter' => '<form method="post" class="newsletter"><div><div class="rows"><div id="email-box"><input name="email" type="email" placeholder="Email address" required></div><div id="submit-box"><button type="submit"><span class="caption">Subscribe</span></button></div></div></div></form>',
    'choice' => '<form method="post" class="choice-form"><div><div class="choice-grid"><label class="choice"><input type="checkbox" name="updates" checked><span>Receive updates</span></label></div></div><button type="submit">Send</button></form>',
    'fragment' => '<form class="choice-form"><div><div class="choice-grid"><label class="choice"><input type="checkbox" checked><span>Receive updates</span></label></div></div></form>',
);
return array('css' => $css, 'forms' => $forms);
