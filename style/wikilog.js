/* JavaScript bits for Wikilog extension */

/* Checks new item name and sets 'title' and 'preload' arguments */
function checkNewItem(f, msg)
{
    var i = document.getElementById('wl-item-name');
    var w = document.getElementById('wl-newitem-wikilog');
    if (i.value.indexOf('/') >= 0)
    {
        alert(msg);
        return false;
    }
    f.title.value = w.value + '/' + i.value;
    f.preload.value = 'Template:' + w.value;
    return true;
}
