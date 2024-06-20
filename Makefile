SHELL=/bin/bash
CVER=2.7.7
SRCURL=https://github.com/composer/composer/releases/download/$(CVER)/composer.phar
FINAL=/usr/local/bin/composer
VERDEST=/usr/local/composer-$(CVER)

$(FINAL): $(VERDEST)
	ln -s $< $@

$(VERDEST):
	@wget -O $@ $(SRCURL) || :
	@if [ ! -s $@ ]; then echo "Could not download $(SRCURL), deleting"; rm -f $@; else chmod 0755 $@; fi

