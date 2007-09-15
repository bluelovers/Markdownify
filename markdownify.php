<?php
/**
 * Markdownify converts HTML Markup to [Markdown][1] (by [John Gruber][2]. It
 * also supports [Markdown Extra][3] by [Michel Fortin][4].
 *
 * It all started as a port of [Aaron Swartz'][5] [`html2text.py`][6] but
 * got a long way since. This is more than a mere port now!
 * Starting with version 2.0.0 this is a complete rewrite and
 * cannot be compared to Aaron Swatz' `html2text.py` anylonger. I'm now using a
 * HTML parser (see `parsehtml.php` which I also wrote) which makes most of the
 * evil RegEx magic go away and additionally it gives a much cleaner class
 * structure. Also notably is the fact that I now try to prevent regressions by
 * utilizing testcases of Michel Fortin's [MDTest][7].
 *
 * [1]: http://daringfireball.com/projects/markdown
 * [2]: http://daringfireball.com/
 * [3]: http://www.michelf.com/projects/php-markdown/extra/
 * [4]: http://www.michelf.com/
 * [5]: http://www.aaronsw.com/
 * [6]: http://www.aaronsw.com/2002/html2text/
 * [7]: http://article.gmane.org/gmane.text.markdown.general/2540
 *
 * @version 2.0.0 alpha
 * @author Milian Wolff (<mail@milianw.de>, <http://milianw.de>)
 * @license LGPL, see LICENSE_LGPL.txt and the summary below
 * @copyright (C) 2007  Milian Wolff
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * HTML Parser, see http://sf.net/projects/parseHTML
 */
require_once(dirname(__FILE__).'/parsehtml/parsehtml.php');

/**
 * HTML to Markdown converter class
 */
class Markdownify {
	/**
	 * current indendation
	 *
	 * @var string
	 */
	var $static = '';
	/**
	 * html parser object
	 *
	 * @var parseHTML
	 */
	var $parser;
	/**
	 * markdown output
	 *
	 * @var string
	 */
	var $output;
	/**
	 * stack with tags which where not converted to html
	 *
	 * @var array<string>
	 */
	var $notConverted = array();
	/**
	 * skip conversion to markdown
	 *
	 * @var bool
	 */
	var $skipConversion = false;
	/* options */
	/**
	 * keep html tags which cannot be converted to markdown
	 *
	 * @var bool
	 */
	var $keepHTML = false;
	/**
	 * wrap output, set to 0 to skip wrapping
	 *
	 * @var int
	 */
	var $bodyWidth = 0;
	/**
	 * minimum body width
	 *
	 * @var int
	 */
	var $minBodyWidth = 25;
	/**
	 * display links after each paragraph
	 *
	 * @var bool
	 */
	var $linksAfterEachParagraph = false;
	/**
	 * constructor, set options, setup parser
	 */
	function Markdownify($linksAfterEachParagraph = false, $bodyWidth = false, $keepHTML = true) {
		$this->linksAfterEachParagraph = $linksAfterEachParagraph;
		$this->keepHTML = $keepHTML;
		$this->bodyWidth = $bodyWidth;

		$this->parser = new parseHTML;

		# we don't have to do this every time
		$this->escapeInText = implode('|', $this->escapeInText);
	}
	/**
	 * parse a HTML string
	 *
	 * @param string $html
	 * @return string markdown formatted
	 */
	function parseString($html) {
		$this->parser->html = $html;
		$this->parse();
		return $this->output;
	}
	/**
	 * tags with elements which can be handled by markdown
	 *
	 * @var array<string>
	 */
	var $isMarkdownable = array(
		'p' => array(),
		'ul' => array(),
		'ol' => array(),
		'li' => array(),
		'br' => array(),
		'blockquote' => array(),
		'code' => array(),
		'pre' => array(),
		'a' => array(
			'href' => 'required',
			'title' => 'optional',
		),
		'strong' => array(),
		'b' => array(),
		'em' => array(),
		'i' => array(),
		'img' => array(
			'src' => 'required',
			'alt' => 'optional',
			'title' => 'optional',
		),
		/** TODO: markdownify_extra **/
		'h1' => array(
			#'id' => 'optional',
		),
		'h2' => array(
			#'id' => 'optional',
		),
		'h3' => array(
			#'id' => 'optional',
		),
		'h4' => array(
			#'id' => 'optional',
		),
		'h5' => array(
			#'id' => 'optional',
		),
		'h6' => array(
			#'id' => 'optional',
		),
		/** TODO: markdownify_extra **/
		#'table' => array(),
		#'th' => array(
		#	'align' => 'optional',
		#),
		#'td' => array(
		#	'align' => 'optional',
		#),
		/** TODO: markdownify_extra **/
		#'sup' => array(
		#	'id' => 'optional',
		#),
		/** TODO: markdownify_extra **/
		#'abbr' => array(
		#	'title' => 'required',
		#),
		/** TODO: markdownify_extra **/
		#'acronym' => array(
		#	'title' => 'required',
		#),
		'hr' => array(),
	);
	/**
	 * html tags to be ignored (contents will be parsed)
	 *
	 * @var array<string>
	 */
	var $ignore = array(
		'html',
		'body',
		'thead',
		'tbody',
		'tfoot',
	);
	/**
	 * html tags to be dropped (contents will not be parsed!)
	 *
	 * @var array<string>
	 */
	var $drop = array(
		'script',
		'head',
		'style',
		'form',
	);
	/**
	 * Markdown indents which could be wrapped
	 * @note: use strings in regex format
	 *
	 * @var array<string>
	 */
	var $wrappableIndents = array(
		'\*   ', # ul
		'\d.  ', # ol
		'\d\d. ', # ol
		'> ', # blockquote
		'', # p
	);
	/**
	 * list of chars which have to be escaped in normal text
	 * @note: use strings in regex format
	 *
	 * @var array <string>
	 *
	 * TODO: what's with block chars / sequences at the beginning of a block?
	 */
	var $escapeInText = array(
		'\*', # emphasis
		'_', # emphasis
		'`', # code
	);
	/**
	 * iterate through the nodes and decide what we
	 * shall do with the current node
	 *
	 * @param void
	 * @return void
	 */
	function parse() {
		$this->output = '';
		# drop tags
		$this->parser->html = preg_replace('#<('.implode('|', $this->drop).')[^>]*>.*</\\1>#sU', '', $this->parser->html);
		while ($this->parser->nextNode()) {
			switch ($this->parser->nodeType) {
				case 'doctype':
				case 'pi':
				case 'comment':
					if ($this->keepHTML) {
						$this->flushLinebreaks();
						$this->out($this->parser->node);
						$this->setLineBreaks(2);
					}
					# else drop
					break;
				case 'text':
					$this->handleText();
					break;
				case 'tag':
					if ($this->skipConversion) {
						$this->isMarkdownable(); # update notConverted
						$this->handleTagToText();
						continue;
					}
					if (in_array($this->parser->tagName, $this->ignore)) {
						$this->ignoreTag();
						break;
					}
					if ($this->parser->isStartTag) {
						$this->flushLinebreaks();
					}
					if (!$this->parser->keepWhitespace && $this->parser->isBlockElement) {
						if ($this->parser->isStartTag) {
							$this->parser->html = ltrim($this->parser->html);
						} elseif ($this->parser->tagName != 'pre') {
							$this->output = rtrim($this->output);
						}
					}
					if ($this->isMarkdownable()) {
						call_user_func(array(&$this, 'handleTag_'.$this->parser->tagName));
						if ($this->linksAfterEachParagraph && $this->parser->isBlockElement && !$this->parser->isStartTag) {
							$this->handleStacked();
						}
					} else {
						$this->handleTagToText();
					}
					break;
				default:
					trigger_error('invalid node type', E_USER_ERROR);
					break;
			}
		}
		### cleanup
		$this->output = rtrim(str_replace('&amp;', '&', str_replace('&lt;', '<', str_replace('&gt;', '>', $this->output))));
		if ($this->bodyWidth) {
			$this->wrapOutput();
		}
		# end parsing, handle stacked tags
		$this->handleStacked();
		$this->stack = array();

	}
	/**
	 * wordwrap output to given length
	 *
	 * @param void
	 * @return void
	 */
	function wrapOutput() {
		/** TODO: links, code tags, html tags and possibly more must not be wrapped **/
		/** TODO: paragraphs inside li, e.g.:
		          1.  Item 1
		          
		              Item 2 with long text which should be wrapped 'cause it aint no code block! **/
		$this->output = preg_replace_callback('#^((?:'.implode('|', $this->wrappableIndents).')+)(?!    ).{'.intval($this->bodyWidth).',}$#m', array(&$this, '_wrapOutput'), $this->output);
	}
	/**
	 * wrapping callback
	 *
	 * @param array $matches
	 * @return string
	 */
	function _wrapOutput($matches) {
		return wordwrap($matches[0], $this->bodyWidth - strlen($matches[1]), "\n".$matches[1], false);
	}
	/**
	 * check if current tag can be converted to Markdown
	 *
	 * @param void
	 * @return bool
	 */
	function isMarkdownable() {
		if (!isset($this->isMarkdownable[$this->parser->tagName])) {
			# simply not markdownable
			return false;
		}
		if ($this->parser->isStartTag) {
			$return = true;
			if ($this->keepHTML) {
				$diff = array_diff(array_keys($this->parser->tagAttributes), array_keys($this->isMarkdownable[$this->parser->tagName]));
				if (!empty($diff)) {
					# non markdownable attributes given
					$return = false;
				}
			}
			if ($return) {
				foreach ($this->isMarkdownable[$this->parser->tagName] as $attr => $type) {
					if ($type == 'required' && !isset($this->parser->tagAttributes[$attr])) {
						# required markdown attribute not given
						$return = false;
						break;
					}
				}
			}
			if (!$return) {
				array_push($this->notConverted, $this->parser->tagName.'::'.implode('/', $this->parser->openTags));
			}
			return $return;
		} else {
			if (!empty($this->notConverted) && end($this->notConverted) === $this->parser->tagName.'::'.implode('/', $this->parser->openTags)) {
				array_pop($this->notConverted);
				return false;
			}
			return true;
		}
	}
	/**
	 * handle stacked links, acronyms
	 *
	 * @param void
	 * @return void
	 */
	function handleStacked() {
		# links
		if (empty($this->stack['a'])) {
			return;
		}
		$out = array();
		foreach ($this->stack['a'] as $k => $tag) {
			if (!isset($tag['unstacked'])) {
				array_push($out, ' ['.$tag['linkID'].']: '.$tag['href'].(!empty($tag['title']) ? ' "'.$tag['title'].'"' : ''));
				$tag['unstacked'] = true;
				$this->stack['a'][$k] = $tag;
			}
		}
		if (!empty($out)) {
			$this->out("\n\n".implode("\n", $out));
		}
	}
	/**
	 * flush enqued linebreaks
	 *
	 * @param void
	 * @return void
	 */
	function flushLinebreaks() {
		if (!empty($this->output)) {
			$this->out(str_repeat("\n".$this->indent, $this->lineBreaks));
		}
		$this->lineBreaks = 0;
	}
	/**
	 * handle non Markdownable tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTagToText() {
		if (!$this->keepHTML) {
			if ($this->parser->isStartTag) {
				$this->flushLinebreaks();
			} else {
				$this->setLineBreaks(2);
			}
		} else {
			# dont convert to markdown inside this tag
			/** TODO: markdown extra **/
			if (!$this->parser->isEmptyTag) {
				if ($this->parser->isStartTag) {
					if (!$this->skipConversion) {
						$this->skipConversion = $this->parser->tagName.'::'.implode('/', $this->parser->openTags);
					}
				} else {
					if ($this->skipConversion == $this->parser->tagName.'::'.implode('/', $this->parser->openTags)) {
						$this->skipConversion = false;
					}
				}
			}

			if ($this->parser->isBlockElement) {
				if ($this->parser->isStartTag) {
					$this->flushLinebreaks();
					$this->out($this->parser->node."\n".$this->indent);
					if (!$this->parser->isEmptyTag) {
						$this->indent('  ');
					} else {
						$this->setLineBreaks(1);
					}
				} else {
					if (!$this->parser->keepWhitespace) {
						$this->output = rtrim($this->output);
					}
					$this->indent('  ');
					$this->out("\n".$this->indent.$this->parser->node);

					if ($this->parser->tagName == 'li') {
						$this->setLineBreaks(1);
					} else {
						$this->setLineBreaks(2);
					}
				}
			} else {
				$this->out($this->parser->node);
			}
		}
	}
	/**
	 * handle plain text
	 *
	 * @param void
	 * @return void
	 */
	function handleText() {
		if ($this->hasParent('pre') && strstr($this->parser->node, "\n")) {
			$this->parser->node = str_replace("\n", "\n".$this->indent, $this->parser->node);
		}
		if (!$this->hasParent('code') && !$this->hasParent('pre')) {
			# entity decode
			$this->decode(&$this->parser->node);
			if (!$this->skipConversion) {
				# escape some chars in normal Text
				$this->parser->node = preg_replace('#('.$this->escapeInText.')(.+)\1#', '\\\\$1$2\\\\$1', $this->parser->node);
			}
		}
		$this->out($this->parser->node);
	}
	/**
	 * handle <em> and <i> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_em() {
		#$this->notice('make it configurable with either * or _');
		$this->out('*');
	}
	function handleTag_i() {
		$this->handleTag_em();
	}
	/**
	 * handle <strong> and <b> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_strong() {
		$this->out('**');
	}
	function handleTag_b() {
		$this->handleTag_strong();
	}
	/**
	 * handle <h1> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_h1() {
		$this->handleHeader(1);
	}
	/**
	 * handle <h2> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_h2() {
		$this->handleHeader(2);
	}
	/**
	 * handle <h3> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_h3() {
		$this->handleHeader(3);
	}
	/**
	 * handle <h4> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_h4() {
		$this->handleHeader(4);
	}
	/**
	 * handle <h5> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_h5() {
		$this->handleHeader(5);
	}
	/**
	 * handle <h6> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_h6() {
		$this->handleHeader(6);
	}
	/**
	 * number of line breaks before next inline output
	 */
	var $lineBreaks = 0;
	/**
	 * handle header tags (<h1> - <h6>)
	 *
	 * @param int $level 1-6
	 * @return void
	 */
	function handleHeader($level) {
		if ($this->parser->isStartTag) {
			/** TODO: setex style headers via config setting **/
			$this->out(str_repeat('#', $level).' ');
		} else {
			$this->setLineBreaks(2);
		}
	}
	/**
	 * handle <p> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_p() {
		if (!$this->parser->isStartTag) {
			$this->setLineBreaks(2);
		}
	}
	/**
	 * handle <a> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_a() {
		if ($this->parser->isStartTag) {
			$this->buffer();
			if (!isset($this->parser->tagAttributes['title'])) {
				$this->parser->tagAttributes['title'] = '';
			} else {
				$this->decode(&$this->parser->tagAttributes['title']);
			}
			$this->parser->tagAttributes['href'] = $this->decode(trim($this->parser->tagAttributes['href']));
			$this->stack();
		} else {
			$tag = $this->unstack();
			$buffer = $this->unbuffer();

			if (empty($tag['href']) && empty($tag['title'])) {
				# empty links... testcase mania, who would possibly do anything like that?!
				$this->out('['.$buffer.']()');
				return;
			}

			if ($buffer == $tag['href'] && empty($tag['title'])) {
				# <http://example.com>
				$this->out('<'.$buffer.'>');
				return;
			}

			$bufferDecoded = $this->decode(trim($buffer));
			if (substr($tag['href'], 0, 7) == 'mailto:' && 'mailto:'.$bufferDecoded == $tag['href']) {
				if (empty($tag['title'])) {
					# <mail@example.com>
					$this->out('<'.$bufferDecoded.'>');
					return;
				}
				# [mail@example.com][1]
				# ...
				#  [1]: mailto:mail@example.com Title
				$tag['href'] = 'mailto:'.$bufferDecoded;
			}
			# [This link][id]
			/** TODO: empty titles **/
			foreach ($this->stack['a'] as &$tag2) {
				if ($tag2['href'] == $tag['href'] && $tag2['title'] == $tag['title']) {
					$tag['linkID'] = $tag2['linkID'];
					break;
				}
			}
			if (!isset($tag['linkID'])) {
				$tag['linkID'] = count($this->stack['a']) + 1;
				array_push($this->stack['a'], $tag);
			}

			$this->out('['.$buffer.']['.$tag['linkID'].']');
		}
	}
	/**
	 * handle <img /> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_img() {
		if (!$this->parser->isStartTag) {
			return; # just to be sure this is really an empty tag...
		}

		# [This link][id]
		$link_id = false;
		/** TODO: empty titles **/
		if (!isset($this->parser->tagAttributes['title'])) {
			$this->parser->tagAttributes['title'] = '';
		} else {
			$this->decode(&$this->parser->tagAttributes['title']);
		}
		/** TODO: empty alt text **/
		if (!isset($this->parser->tagAttributes['alt'])) {
			$this->parser->tagAttributes['alt'] = $this->parser->tagAttributes['title'];
		} else {
			$this->decode(&$this->parser->tagAttributes['alt']);
		}

		if (empty($this->parser->tagAttributes['src'])) {
			# support for "empty" images... dunno if this is really needed
			# but there are some testcases which do that...
			if (!empty($this->parser->tagAttributes['title'])) {
				$this->parser->tagAttributes['title'] = ' '.$this->parser->tagAttributes['title'].' ';
			}
			$this->out('!['.$this->parser->tagAttributes['alt'].']('.$this->parser->tagAttributes['title'].')');
			return;
		} else {
			$this->decode(&$this->parser->tagAttributes['src']);
		}

		if (!empty($this->stack['a'])) {
			foreach ($this->stack['a'] as $tag) {
				if ($tag['href'] == $this->parser->tagAttributes['src'] && $tag['title'] == $this->parser->tagAttributes['title']) {
					$link_id = $tag['linkID'];
					break;
				}
			}
		} else {
			$this->stack['a'] = array();
		}
		if (!$link_id) {
			$link_id = count($this->stack['a']) + 1;
			array_push($this->stack['a'], array(
				'href' => $this->parser->tagAttributes['src'],
				'title' => $this->parser->tagAttributes['title'],
				'linkID' => $link_id,
			));
		}

		$this->out('!['.$this->parser->tagAttributes['alt'].']['.$link_id.']');
	}
	/**
	 * handle <code> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_code() {
		if ($this->hasParent('pre')) {
			# ignore code blocks inside <pre>
			return;
		}
		if ($this->parser->isStartTag) {
			$this->buffer();
		} else {
			$buffer = $this->unbuffer();
			# use as many backticks as needed
			preg_match_all('#`+#', $buffer, $matches);
			if (!empty($matches[0])) {
				rsort($matches[0]);

				$ticks = '`';
				while (true) {
					if (!in_array($ticks, $matches[0])) {
						break;
					}
					$ticks .= '`';
				}
			} else {
				$ticks = '`';
			}
			if ($buffer[0] == '`' || substr($buffer, -1) == '`') {
				$buffer = ' '.$buffer.' ';
			}
			$this->out($ticks.$buffer.$ticks);
		}
	}
	/**
	 * handle <pre> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_pre() {
		$this->indent('    ');
		if (!$this->parser->isStartTag) {
			$this->setLineBreaks(2);
		} else {
			$this->parser->html = ltrim($this->parser->html);
		}
	}
	/**
	 * handle <blockquote> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_blockquote() {
		$this->indent('> ');
	}
	/**
	 * handle <ul> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_ul() {
		if ($this->parser->isStartTag) {
			$this->stack();
			if (substr($this->output, -strlen("\n".$this->indent)) != "\n".$this->indent) {
				$this->out("\n".$this->indent);
			}
		} else {
			$this->unstack();
			$this->setLineBreaks(2);
		}
	}
	/**
	 * handle <ul> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_ol() {
		# same as above
		$this->parser->tagAttributes['num'] = 0;
		$this->handleTag_ul();
		if (!$this->parser->isStartTag) {
			$this->setLineBreaks(2);
		} else {
			if (substr($this->output, -strlen("\n".$this->indent)) != "\n".$this->indent) {
				$this->out("\n".$this->indent);
			}
		}
	}
	/**
	 * handle <li> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_li() {
		if ($this->parent() == 'ol') {
			$parent =& $this->getStacked('ol');
			if ($this->parser->isStartTag) {
				$parent['num']++;
				$this->out($parent['num'].'.'.str_repeat(' ', 3 - strlen($parent['num'])));
			}
			$this->indent('    ', false);
		} else {
			if ($this->parser->isStartTag) {
				$this->out('*   ');
			}
			#$this->notice('configurable list char: * - +');
			$this->indent('    ', false);
		}
		if (!$this->parser->isStartTag) {
			$this->setLineBreaks(1);
		}
	}
	/**
	 * handle <hr /> tags
	 *
	 * @param void
	 * @return void
	 */
	function handleTag_hr() {
		if (!$this->parser->isStartTag) {
			return; # just to be sure this really is an empty tag
		}
		#$this->notice('configurable hr');
		$this->out('* * *');
		$this->setLineBreaks(2);
	}
	/**
	 * node stack, e.g. for <a> and <abbr> tags
	 *
	 * @var array<array>
	 */
	var $stack = array();
	/**
	 * add current node to the stack
	 * this only stores the attributes
	 *
	 * @param void
	 * @return void
	 */
	function stack() {
		if (!isset($this->stack[$this->parser->tagName])) {
			$this->stack[$this->parser->tagName] = array();
		}
		array_push($this->stack[$this->parser->tagName], $this->parser->tagAttributes);
	}
	/**
	 * remove current tag from stack
	 *
	 * @param void
	 * @return array
	 */
	function unstack() {
		if (!isset($this->stack[$this->parser->tagName]) || !is_array($this->stack[$this->parser->tagName])) {
			trigger_error('somebody set us up the bomb', E_USER_ERROR);
		}
		return array_pop($this->stack[$this->parser->tagName]);
	}
	/**
	 * get last stacked element of type $tagName
	 *
	 * @param string $tagName
	 * @return array
	 */
	function & getStacked($tagName) {
		// no end() so it can be referenced
		return $this->stack[$tagName][count($this->stack[$tagName])-1];
	}
	/**
	 * set number of line breaks before next start tag
	 *
	 * @param int $number
	 * @return void
	 */
	function setLineBreaks($number) {
		if ($this->lineBreaks < $number) {
			$this->lineBreaks = $number;
		}
	}
	/**
	 * stores current buffers
	 *
	 * @var array<string>
	 */
	var $buffer = array();
	/**
	 * buffer next parser output until unbuffer() is called
	 *
	 * @param void
	 * @return void
	 */
	function buffer() {
		array_push($this->buffer, '');
	}
	/**
	 * end current buffer and return buffered output
	 *
	 * @param void
	 * @return string
	 */
	function unbuffer() {
		return array_pop($this->buffer);
	}
	/**
	 * append string to the correct var, either
	 * directly to $this->output or to the current
	 * buffers
	 *
	 * @param string $put
	 * @return void
	 */
	function out($put) {
		if (!empty($this->buffer)) {
			$this->buffer[count($this->buffer)-1] .= $put;
		} else {
			$this->output .= $put;
		}
	}
	/**
	 * current indentation
	 *
	 * @var string
	 */
	var $indent = '';
	/**
	 * indent next output (start tag) or unindent (end tag)
	 *
	 * @param string $str indentation
	 * @param bool $output add indendation to output
	 * @return void
	 */
	function indent($str, $output = true) {
		if ($this->parser->isStartTag) {
			$this->indent .= $str;
			if ($output) {
				$this->out($str);
			}
		} else {
			$this->indent = substr($this->indent, 0, -strlen($str));
		}
	}
	/**
	 * decode email addresses
	 *
	 * @author derernst@gmx.ch <http://www.php.net/manual/en/function.html-entity-decode.php#68536>
	 */
	function decode($text, $quote_style = ENT_NOQUOTES) {
		if (function_exists('html_entity_decode')) {
			$text = html_entity_decode($text, $quote_style, 'ISO-8859-1');
		}
		else {
			$trans_tbl = get_html_translation_table(HTML_ENTITIES, $quote_style);
			$trans_tbl = array_flip($trans_tbl);
			$text = strtr($text, $trans_tbl);
		}
		$text = preg_replace('~&#x([0-9a-f]+);~ei', 'chr(hexdec("\\1"))', $text);
		$text = preg_replace('~&#([0-9]+);~e', 'chr("\\1")', $text);
		return $text;
	}
	/**
	 * check if current node has a $tagName as parent (somewhere, not only the direct parent)
	 *
	 * @param string $tagName
	 * @return bool
	 */
	function hasParent($tagName) {
		return in_array($tagName, $this->parser->openTags);
	}
	/**
	 * get tagName of direct parent tag
	 *
	 * @param void
	 * @return string $tagName
	 */
	function parent() {
		return end($this->parser->openTags);
	}

	/* debug functions */
	function todo($message = false) {
		die('TODO: '.($message ? $message : '')."\n".called()."\n".str_repeat('-', 75)."\n".$this->parser->node.$this->parser->html);
	}
	function notice($message = false) {
		static $already_called = array();
		$called = called();
		if (in_array($called, $already_called))
			return;

		print("\nnotice: ".($message ? $message : '')."\n".$called."\n\n");
		array_push($already_called, $called);
	}
	function debug_pos($len = 25) {
		var_dump(substr($this->output, -$len));
		var_dump(substr($this->parser->html, 0, $len));
	}
}
function dump() {
	$args = func_get_args();
	ob_start();
	call_user_func_array('xdebug_var_dump', $args);
	$dump = ob_get_contents();
	ob_end_clean();
	return $dump;
}
function called() {
	$trace = debug_backtrace();
	$return = "\t".'triggered by ';
	# calling function / method
	if (isset($trace[2]['class'])) {
		$return .= $trace[2]['class'].'->';
	}
	return $return.$trace[2]['function'].'() on line '.$trace[1]['line'];
}