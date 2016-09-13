# WP Enqueue Masher

WP Enqueue Masher minifies & concatenate enqueued scripts & styles.

It is a fork of Automattic's [nginx-http-concat](https://github.com/Automattic/nginx-http-concat).


* Invisible once activated & configured
* Safe, secure, & performant

# Installation

* Download and install using the built in WordPress plugin installer.
* Activate in the "Plugins" area of your admin by clicking the "Activate" link. You may want to network activate this for multisite installations, or modify the configuration to be a "Must-Use" plugin.
* Drag `./drop-ins/wp-concat.php` into the root of your WordPress installation
* Add this nginx rule to your WordPress configuration:

```
location /s/ {
    fastcgi_pass   php; # You may use something else (hhvm,  unix:/var/run/fastcgi.sock, etc...)
    include        /etc/nginx/fastcgi_params;
    fastcgi_param  SCRIPT_FILENAME $document_root/wp-concat.php;
}
```

# FAQ

### What exactly does this do?

Concatenates individual scripts and styles.

### Are database tables modified?

No. All of WordPress's core database tables remain untouched.

### Where can I get support?

The WordPress support forums: https://wordpress.org/support/plugin/wp-enqueue-masher/

### Can I contribute?

Yes, please! Having an easy-to-use plugin and powerful set of functions is critical to managing complex WordPress installations. If this is your thing, please help us out!
