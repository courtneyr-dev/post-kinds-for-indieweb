/**
 * Reactions for IndieWeb - Kind Icons
 *
 * SVG icons for each post kind type.
 *
 * @package
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { SVG, Path, Circle } from '@wordpress/primitives';

/**
 * Note icon - simple document/text
 *
 * @return {JSX.Element} SVG icon.
 */
export const NoteIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-1 14H6v-1.5h12V17zm0-3H6v-1.5h12V14zm0-3H6V9.5h12V11z" />
	</SVG>
);

/**
 * Article icon - document with title
 *
 * @return {JSX.Element} SVG icon.
 */
export const ArticleIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-1 4H6V5.5h12V7zm0 3H6V8.5h12V10zm0 3H6v-1.5h12V13zm-4 3H6v-1.5h8V16z" />
	</SVG>
);

/**
 * Reply icon - speech bubble with arrow
 *
 * @return {JSX.Element} SVG icon.
 */
export const ReplyIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" />
	</SVG>
);

/**
 * Like icon - heart
 *
 * @return {JSX.Element} SVG icon.
 */
export const LikeIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
	</SVG>
);

/**
 * Repost icon - refresh/share arrows
 *
 * @return {JSX.Element} SVG icon.
 */
export const RepostIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z" />
	</SVG>
);

/**
 * Bookmark icon - flag/bookmark
 *
 * @return {JSX.Element} SVG icon.
 */
export const BookmarkIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M17 3H7c-1.1 0-2 .9-2 2v16l7-3 7 3V5c0-1.1-.9-2-2-2z" />
	</SVG>
);

/**
 * RSVP icon - calendar with check
 *
 * @return {JSX.Element} SVG icon.
 */
export const RSVPIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zm-7.5-5l-3.5 3.5 1.5 1.5 2-2 4 4 1.5-1.5-5.5-5.5z" />
	</SVG>
);

/**
 * Checkin icon - location pin
 *
 * @return {JSX.Element} SVG icon.
 */
export const CheckinIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
	</SVG>
);

/**
 * Listen icon - headphones/music note
 *
 * @return {JSX.Element} SVG icon.
 */
export const ListenIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" />
	</SVG>
);

/**
 * Watch icon - film/movie
 *
 * @return {JSX.Element} SVG icon.
 */
export const WatchIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z" />
	</SVG>
);

/**
 * Read icon - open book
 *
 * @return {JSX.Element} SVG icon.
 */
export const ReadIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M21 5c-1.11-.35-2.33-.5-3.5-.5-1.95 0-4.05.4-5.5 1.5-1.45-1.1-3.55-1.5-5.5-1.5S2.45 4.9 1 6v14.65c0 .25.25.5.5.5.1 0 .15-.05.25-.05C3.1 20.45 5.05 20 6.5 20c1.95 0 4.05.4 5.5 1.5 1.35-.85 3.8-1.5 5.5-1.5 1.65 0 3.35.3 4.75 1.05.1.05.15.05.25.05.25 0 .5-.25.5-.5V6c-.6-.45-1.25-.75-2-1zm0 13.5c-1.1-.35-2.3-.5-3.5-.5-1.7 0-4.15.65-5.5 1.5V8c1.35-.85 3.8-1.5 5.5-1.5 1.2 0 2.4.15 3.5.5v11.5z" />
	</SVG>
);

/**
 * Event icon - calendar
 *
 * @return {JSX.Element} SVG icon.
 */
export const EventIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />
	</SVG>
);

/**
 * Photo icon - image/camera
 *
 * @return {JSX.Element} SVG icon.
 */
export const PhotoIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Circle cx="12" cy="12" r="3.2" />
		<Path d="M9 2L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z" />
	</SVG>
);

/**
 * Video icon - play button
 *
 * @return {JSX.Element} SVG icon.
 */
export const VideoIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z" />
	</SVG>
);

/**
 * Review icon - star
 *
 * @return {JSX.Element} SVG icon.
 */
export const ReviewIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
	</SVG>
);

/**
 * Recipe icon - utensils
 *
 * @return {JSX.Element} SVG icon.
 */
export const RecipeIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3v8h2.5v8H21V2c-2.76 0-5 2.24-5 4z" />
	</SVG>
);

/**
 * Favorite icon - star outline
 *
 * @return {JSX.Element} SVG icon.
 */
export const FavoriteIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z" />
	</SVG>
);

/**
 * Jam icon - music with fire/highlight
 *
 * @return {JSX.Element} SVG icon.
 */
export const JamIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 3v9.28c-.47-.17-.97-.28-1.5-.28C8.01 12 6 14.01 6 16.5S8.01 21 10.5 21c2.31 0 4.2-1.75 4.45-4H15V6h4V3h-7z" />
		<Path d="M19.48 12.35c-1.57-1.57-3.56-1.14-4.3-.24l.74.74c.42-.56 1.63-.86 2.78.29.58.58.58 1.53 0 2.12l-1.41 1.41 1.06 1.06 1.41-1.41c1.17-1.17 1.17-3.08-.28-3.97z" />
	</SVG>
);

/**
 * Wish icon - gift/present
 *
 * @return {JSX.Element} SVG icon.
 */
export const WishIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M20 6h-2.18c.11-.31.18-.65.18-1 0-1.66-1.34-3-3-3-1.05 0-1.96.54-2.5 1.35l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zM9 4c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm11 15H4v-2h16v2zm0-5H4V8h5.08L7 10.83 8.62 12 11 8.76l1-1.36 1 1.36L15.38 12 17 10.83 14.92 8H20v6z" />
	</SVG>
);

/**
 * Mood icon - emoji face
 *
 * @return {JSX.Element} SVG icon.
 */
export const MoodIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z" />
	</SVG>
);

/**
 * Acquisition icon - shopping bag/box
 *
 * @return {JSX.Element} SVG icon.
 */
export const AcquisitionIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M18 6h-2c0-2.21-1.79-4-4-4S8 3.79 8 6H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6-2c1.1 0 2 .9 2 2h-4c0-1.1.9-2 2-2zm6 16H6V8h2v2c0 .55.45 1 1 1s1-.45 1-1V8h4v2c0 .55.45 1 1 1s1-.45 1-1V8h2v12z" />
	</SVG>
);

/**
 * Drink icon - coffee cup/beverage
 *
 * @return {JSX.Element} SVG icon.
 */
export const DrinkIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M20 3H4v10c0 2.21 1.79 4 4 4h6c2.21 0 4-1.79 4-4v-3h2c1.11 0 2-.89 2-2V5c0-1.11-.89-2-2-2zm0 5h-2V5h2v3zM4 19h16v2H4z" />
	</SVG>
);

/**
 * Eat icon - fork and knife/plate
 *
 * @return {JSX.Element} SVG icon.
 */
export const EatIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M8.1 13.34l2.83-2.83L3.91 3.5c-1.56 1.56-1.56 4.09 0 5.66l4.19 4.18zm6.78-1.81c1.53.71 3.68.21 5.27-1.38 1.91-1.91 2.28-4.65.81-6.12-1.46-1.46-4.2-1.1-6.12.81-1.59 1.59-2.09 3.74-1.38 5.27L3.7 19.87l1.41 1.41L12 14.41l6.88 6.88 1.41-1.41L13.41 13l1.47-1.47z" />
	</SVG>
);

/**
 * Play icon - game controller
 *
 * @return {JSX.Element} SVG icon.
 */
export const PlayIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M21.58 16.09l-1.09-7.66C20.21 6.46 18.52 5 16.53 5H7.47C5.48 5 3.79 6.46 3.51 8.43l-1.09 7.66C2.2 17.63 3.39 19 4.94 19h0c.68 0 1.32-.27 1.8-.75L9 16h6l2.25 2.25c.48.48 1.13.75 1.8.75h0C20.61 19 21.8 17.63 21.58 16.09zM11 11H9v2H8v-2H6v-1h2V8h1v2h2V11zm4-1c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1S15.55 10 15 10zm2 3c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1S17.55 13 17 13z" />
	</SVG>
);

/**
 * Map of kind slugs to icon components.
 *
 * @type {Object}
 */
export const kindIcons = {
	note: NoteIcon,
	article: ArticleIcon,
	reply: ReplyIcon,
	like: LikeIcon,
	repost: RepostIcon,
	bookmark: BookmarkIcon,
	rsvp: RSVPIcon,
	checkin: CheckinIcon,
	listen: ListenIcon,
	watch: WatchIcon,
	read: ReadIcon,
	event: EventIcon,
	photo: PhotoIcon,
	video: VideoIcon,
	review: ReviewIcon,
	recipe: RecipeIcon,
	favorite: FavoriteIcon,
	jam: JamIcon,
	wish: WishIcon,
	mood: MoodIcon,
	acquisition: AcquisitionIcon,
	drink: DrinkIcon,
	eat: EatIcon,
	play: PlayIcon,
};

export default kindIcons;
