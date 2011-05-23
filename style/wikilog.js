/* JavaScript bits for Wikilog extension */

/* Checks new item name and sets 'title' and 'preload' arguments */
function checkNewItem(f, msg)
{
    if (f.wlItemName.value.indexOf('/') >= 0)
    {
        alert(msg);
        return false;
    }
    f.title.value = f.wlWikilog.value + '/' + f.wlItemName.value;
    f.preload.value = 'Template:' + f.wlWikilog.value;
    return true;
}
