import { Node, mergeAttributes } from '@tiptap/react';
import { parseVideoUrl } from '../media/videoEmbed';

declare module '@tiptap/react' {
    interface Commands<ReturnType> {
        videoEmbed: {
            /** Insert a YouTube or Vimeo player for the link. Does nothing for other links. */
            setVideoEmbed: (url: string) => ReturnType;
        };
    }
}

/**
 * A YouTube or Vimeo video in a blog post. In Markdown it is the video link on a line of its own, which the
 * server turns into a player (see BlogPost::html()), so posts stay plain Markdown.
 */
export const VideoEmbedNode = Node.create({
    name: 'videoEmbed',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return { src: { default: null } };
    },

    parseHTML() {
        return [{ tag: 'div[data-video-embed]', getAttrs: (element) => ({ src: (element as HTMLElement).dataset.videoEmbed ?? null }) }];
    },

    renderHTML({ node, HTMLAttributes }) {
        const video = parseVideoUrl(node.attrs.src as string | null);

        return ['div', mergeAttributes(HTMLAttributes, { 'data-video-embed': node.attrs.src, class: 'video-embed' }),
            video
                ? ['iframe', { src: video.embedUrl, title: 'Video preview', loading: 'lazy', referrerpolicy: 'strict-origin-when-cross-origin', allowfullscreen: 'true', tabindex: '-1' }]
                : ['p', {}, String(node.attrs.src ?? '')]];
    },

    addCommands() {
        return {
            setVideoEmbed: (url) => ({ commands }) => parseVideoUrl(url) !== null && commands.insertContent({ type: this.name, attrs: { src: url.trim() } }),
        };
    },

    markdownTokenName: 'videoEmbed',

    markdownTokenizer: {
        name: 'videoEmbed',
        level: 'block',
        // Where a paragraph must stop because a video line starts. Marked passes the text after the first
        // character, so only a link right after a line break counts as the start of a line.
        start: (src: string) => {
            for (const match of src.matchAll(/\n<?(https?:\/\/[^\s<>]+)>?[ \t]*(?=\n|$)/g)) {
                if (parseVideoUrl(match[1])) {
                    return (match.index ?? 0) + 1;
                }
            }

            return -1;
        },
        tokenize: (src: string) => {
            const match = src.match(/^<?(https?:\/\/[^\s<>]+)>?[ \t]*(?:\n|$)/);
            const rest = match ? src.slice(match[0].length) : '';

            // Only a link that is a paragraph of its own, and only for YouTube and Vimeo.
            if (!match || !parseVideoUrl(match[1]) || !/^(?:[ \t]*\n|[ \t]*$)/.test(rest)) {
                return undefined;
            }

            // The blank lines after the link belong to the video, so they are not read back as an empty paragraph.
            return { type: 'videoEmbed', raw: match[0] + (rest.match(/^(?:[ \t]*\n)*/)?.[0] ?? ''), url: match[1] };
        },
    },

    parseMarkdown: (token, helpers) => helpers.createNode('videoEmbed', { src: (token as { url?: string }).url ?? null }),

    renderMarkdown: (node) => String(node.attrs?.src ?? ''),
});
