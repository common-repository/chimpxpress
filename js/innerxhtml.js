/**
 * @file
 * innerXHTML
 *
 * @author Steve Tucker, http://www.stevetucker.co.uk/
 * @see https://github.com/sun/innerXHTML
 * @license MIT/Expat, see LICENSE-MIT.txt
 * @license GNU General Public License v2, see LICENSE-GPL.txt
 */
chimpx_innerXHTML = function(source, string, appendage) {
  // (v0.4) Written 2006 by Steve Tucker, http://www.stevetucker.co.uk
  if (typeof(source) == 'string') {
    source = document.getElementById(source);
  }
  if (!(source.nodeType == 1)) {
    return false;
  }
  var children = source.childNodes;
  var xhtml = '';
  if (!string) {
    for (var i = 0; i < children.length; i++) {
      if (children[i].nodeType == 3) {
        var text_content = children[i].nodeValue;
        text_content = text_content.replace(/</g, '&lt;');
        text_content = text_content.replace(/>/g, '&gt;');
        xhtml += text_content;
      }
      else if (children[i].nodeType == 8) {
        xhtml += '<!--' + children[i].nodeValue + '-->';
      }
      else {
        xhtml += '<' + children[i].nodeName.toLowerCase();
        var attributes = children[i].attributes;
        for (var j = 0; j < attributes.length; j++) {
          var attName = attributes[j].nodeName.toLowerCase();
          var attValue = attributes[j].nodeValue;
          if (attName == 'style' && children[i].style.cssText) {
            xhtml += ' style="' + children[i].style.cssText.toLowerCase() + '"';
          }
          else if (attValue && attName != 'contenteditable') {
            xhtml += ' ' + attName + '="' + attValue + '"';
          }
        }
        xhtml += '>' + chimpx_innerXHTML(children[i]);
        xhtml += '</' + children[i].nodeName.toLowerCase() + '>';
      }
    }
  }
  else {
    if (!appendage) {
      while (children.length>0) {
        source.removeChild(children[0]);
      }
      appendage = false;
    }
    xhtml = string;
    while (string) {
      var returned = chimpx_translateXHTML(string);
      var elements = returned[0];
      string = returned[1];
      if (elements) {
        if (typeof(appendage) == 'string') {
          appendage = document.getElementById(appendage);
        }
        if (!(appendage.nodeType == 1)) {
          source.appendChild(elements);
        }
        else {
          source.insertBefore(elements, appendage);
        }
      }
    }
  }
  return xhtml;
}

function chimpx_translateXHTML(string) {
  var match = /^<\/[a-z0-9]{1,}>/i.test(string);
  if (match) {
    return [false, string.replace(/^<\/[a-z0-9]{1,}>/i, '')];
  }
  match = /^<[a-z]{1,}/i.test(string);
  if (match) {
    string = string.replace(/^</, '');
    var element = string.match(/[a-z0-9]{1,}/i);
    if (element) {
      var new_element = document.createElement(element[0]);
      string = string.replace(/[a-z0-9]{1,}/i, '');
      var attribute = true;
      while (attribute) {
        string = string.replace(/^\s{1,}/, '');
        attribute = string.match(/^[a-z1-9_-]{1,}="[^"]{0,}"/i);
        if (attribute) {
          attribute = attribute[0];
          string = string.replace(/^[a-z1-9_-]{1,}="[^"]{0,}"/i, '');
          var attName = attribute.match(/^[a-z1-9_-]{1,}/i);
          attribute = attribute.replace(/^[a-z1-9_-]{1,}="/i, '');
          attribute = attribute.replace(/;{0,1}"/, '');
          if (attribute) {
            var attValue = attribute;
            if (attName == 'value') {
              new_element.value = attValue;
            }
            else if (attName == 'class') {
              new_element.className = attValue;
            }
            else if (attName == 'style') {
              var style = attValue.split(';');
              for (var i = 0; i < style.length; i++) {
                var this_style = style[i].split(':');
                this_style[0] = this_style[0].toLowerCase().replace(/(^\s{0,})|(\s{0,1})$/, '');
                this_style[1] = this_style[1].toLowerCase().replace(/(^\s{0,})|(\s{0,1})$/, '');
                if (/-{1,}/g.test(this_style[0])) {
                  var this_style_words = this_style[0].split(/-/g);
                  this_style[0] = '';
                  for (var j = 0; j < this_style_words.length; j++) {
                    if (j==0) {
                      this_style[0] = this_style_words[0];
                      continue;
                    }
                    var first_letter = this_style_words[j].toUpperCase().match(/^[a-z]{1,1}/i);
                    this_style[0] += first_letter + this_style_words[j].replace(/^[a-z]{1,1}/, '');
                  }
                }
                new_element.style[this_style[0]] = this_style[1];
              }
            }
            else if (/^on/.test(attName)) {
              new_element[attName] = function() {
                eval(attValue);
              };
            }
            else {
              new_element.setAttribute(attName, attValue);
            }
          }
          else {
            attribute = true;
          }
        }
      }
      match = /^>/.test(string);
      if (match) {
        string = string.replace(/^>/, '');
        var child = true;
        while (child) {
          var returned = chimpx_translateXHTML(string, false);
          child = returned[0];
          if (child) {
            new_element.appendChild(child);
          }
          string = returned[1];
        }
      }
      string = string.replace(/^\/>/, '');
    }
  }
  match = /^[^<>]{1,}/i.test(string);
  if (match && !new_element) {
    var text_content = string.match(/^[^<>]{1,}/i)[0];
    text_content = text_content.replace(/&lt;/g, '<');
    text_content = text_content.replace(/&gt;/g, '>');
    var new_element = document.createTextNode(text_content);
    string = string.replace(/^[^<>]{1,}/i, '');
  }
  match = /^<!--[^<>]{1,}-->/i.test(string);
  if (match && !new_element) {
    if (document.createComment) {
      string = string.replace(/^<!--/i, '');
      var text_content = string.match(/^[^<>]{0,}-->{1,}/i);
      text_content = text_content[0].replace(/-->{1,1}$/, '');
      var new_element = document.createComment(text_content);
      string = string.replace(/^[^<>]{1,}-->/i, '');
    }
    else {
      string = string.replace(/^<!--[^<>]{1,}-->/i, '');
    }
  }
  return [new_element, string];
}
