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

$(document).ready(function(){
    $(".TablePager .wl-unread .TablePager_col_wlp_title .wl-unread-link").click(function(){
        var $self = $(this);
        var id = $self.data("id");

        var $load = $('<span class="wl-unread-link"></span>');
        var i = 0;
        var iID = setInterval(function(){
            i++;
            var text = "";
            for (var j = 0; j < i; j++)
            {
                text += '.';
            }
            $load.text(text);
            if (i >= 3)
            {
                i =0;
            }
        }, 300);
        $self.after($load);
        $self.hide();

        $.ajax({
            type: "GET",
            url: mw.util.wikiScript(),
            data: {
                action:'ajax',
                rs:'Wikilog::markRead',
                rsargs:[id]
            },
            dataType: 'json',
            success: function(result){
                clearInterval(iID);
                $load.remove();
                if (result.error)
                {
                    $self.show();
                }
                else
                {
                    var $tr = $self.parents(".wl-unread");
                    $tr.removeClass("wl-unread");
                    var $comCount = $tr.find('.TablePager_col_wti_num_comments > a');
                    var text = $comCount.text().match(/^(\d+?)\s*\(.*\)/);
                    if (text == null)
                    {
                        text = $comCount.text();
                    }
                    else
                    {
                        text = text[1];
                    }
                    $comCount.text(text);
                    $self.remove();
                }
            },
            error : function(result){
                clearInterval(iID);
                $load.remove();
                $self.show();
            }
        });

        return false;
    });
});
