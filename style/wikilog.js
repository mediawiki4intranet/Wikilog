/* JavaScript bits for Wikilog extension */

/* Checks new item name and sets 'title' and 'preload' arguments */
function checkNewItem(f, msgs)
{
    var i = document.getElementById('wl-item-name');
    var w = document.getElementById('wl-newitem-wikilog');
    if (i.value.indexOf('/') >= 0)
    {
        alert(msgs.subpage);
        return false;
    }
    if (msgs.title)
    {
        if (msgs.title.lng < (w.value + '/' + i.value).length)
        {
            alert(msgs.title.msg);
            return false;
        }
    }
    f.title.value = w.value + '/' + i.value;
    f.preload.value = 'Template:' + w.value;
    return true;
}
