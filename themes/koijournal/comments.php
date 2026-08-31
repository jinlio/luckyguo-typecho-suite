<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<section id="comments" class="comments" aria-labelledby="comments-title">
    <?php $this->comments()->to($comments); ?>
    <div class="comments-heading">
        <h2 id="comments-title"><?php $this->commentsNum(_t('评论'), _t('1 条评论'), _t('%d 条评论')); ?></h2>
        <?php if (suite_flag($this->options, 'showCommentsFeed', true)): ?><a href="<?php $this->options->commentsFeedUrl(); ?>">订阅评论 RSS</a><?php endif; ?>
    </div>

    <?php if ($comments->have()): ?>
        <?php $comments->listComments([
            'before' => '<ol class="comment-list">',
            'after' => '</ol>',
            'dateFormat' => 'Y.m.d H:i',
            'avatarSize' => 44,
            'defaultAvatar' => $this->options->themeUrl('assets/default-avatar.svg', $this->options->theme)
        ]); ?>
        <?php $comments->pageNav('较新', '较旧', 2, '…'); ?>
    <?php endif; ?>

    <?php if ($this->allow('comment')): ?>
        <div class="respond" id="<?php $this->respondId(); ?>">
            <div class="cancel-comment-reply"><?php $comments->cancelReply('取消回复'); ?></div>
            <h3 id="response">留下你的想法</h3>
            <?php if ($this->user->hasLogin()): ?>
                <p class="respond-login">当前以 <a href="<?php $this->options->profileUrl(); ?>"><?php $this->user->screenName(); ?></a> 身份登录，<a href="<?php $this->options->logoutUrl(); ?>">退出</a></p>
            <?php endif; ?>
            <form method="post" action="<?php $this->commentUrl(); ?>" class="respond-form" id="comment-form" role="form">
                <?php if (!$this->user->hasLogin()): ?>
                    <div class="comment-tips" role="note">
                        <p>访客无需注册即可评论。称呼和内容为必填<?php if ($this->options->commentsRequireMail): ?>，Email 也为必填<?php endif; ?>；评论提交后先进入审核队列，通过后才会显示。</p>
                    </div>
                    <div class="form-grid">
                        <p><label for="author">称呼 <span aria-hidden="true">*</span></label><input type="text" name="author" id="author" autocomplete="nickname" placeholder="想被怎么称呼?" value="<?php $this->remember('author'); ?>" required><small class="field-hint">公开显示在评论旁</small></p>
                        <p><label for="mail">Email<?php if ($this->options->commentsRequireMail): ?> <span aria-hidden="true">*</span><?php endif; ?></label><input type="email" name="mail" id="mail" autocomplete="email" placeholder="name@example.com" value="<?php $this->remember('mail'); ?>"<?php if ($this->options->commentsRequireMail): ?> required<?php endif; ?>><small class="field-hint">用于回复通知，不会公开；头像来源由站点设置决定</small></p>
                        <p><label for="url">网站<?php if ($this->options->commentsRequireUrl): ?> <span aria-hidden="true">*</span><?php endif; ?></label><input type="url" name="url" id="url" autocomplete="url" placeholder="https://your.site (选填)" value="<?php $this->remember('url'); ?>"<?php if ($this->options->commentsRequireUrl): ?> required<?php endif; ?>><small class="field-hint">留空也行; 添加后会自动加 rel="nofollow"</small></p>
                    </div>
                <?php endif; ?>
                <p><label for="textarea">内容 <span aria-hidden="true">*</span></label><textarea name="text" id="textarea" rows="5" placeholder="说点什么…&#10;&#10;支持换行；同一用户 60 秒内只能发一条。" required><?php $this->remember('text'); ?></textarea><small class="field-hint">支持基础 Markdown（粗体、链接、代码块）；提交后经审核再展示</small></p>
                <p class="form-actions">
                    <button type="submit">提交评论</button>
                    <small class="submit-hint">提交后需经审核才会显示</small>
                </p>
            </form>
        </div>
    <?php else: ?>
        <p class="respond-login">这篇内容暂时关闭了评论。</p>
    <?php endif; ?>
</section>
