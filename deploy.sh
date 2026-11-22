#!/bin/sh
# Mise à jour automatique de danxome.alwaysdata.net depuis le dépôt public danxome-web
B=https://raw.githubusercontent.com/akiyobabatounde-dotcom/danxome-web/main
T=$HOME/.danxome_tmp; mkdir -p "$T"
V=$(wget -q -O - "$B/version.txt?t=$(date +%s)") || exit 0
[ "$V" = "$(cat $HOME/www/version.txt 2>/dev/null)" ] && exit 0
for f in index.html privacy.html manifest.webmanifest sw.js icon.svg icon-192.png icon-512.png icon-maskable-512.png htaccess.txt api.php stats.html version.txt; do
  wget -q -O "$T/$f" "$B/$f?v=$V" || exit 1
done
[ $(wc -c < "$T/index.html") -gt 100000 ] || exit 1
mv "$T/htaccess.txt" "$HOME/www/.htaccess"
mv "$T"/* "$HOME/www/"
echo "Danxome mis a jour : version $V"
