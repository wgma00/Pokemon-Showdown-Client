/**
 * Room-list panel (default right-panel)
 *
 * @author Guangcong Luo <guangcongluo@gmail.com>
 * @license AGPLv3
 */

class RoomsRoom extends PSRoom {
	readonly classType: string = 'mainmenu';
}

class RoomsPanel extends PSRoomPanel {
	render() {
		const roomInfo = (link: string, numUsers: number, name: string, desc: string) => {
			return <div>
				<a 
					href={link}
					class="ilink">
						<small 
							style="float:right">({numUsers} users)
						</small>
						<strong>
							<i class="fa fa-comment-o"></i> {name} <br></br>
						</strong>
						<small> {desc} </small>
					</a>
				</div>
		};
		const rooms = [{link: '/lobby', users: 10, name: 'Lobby', desc: 'Welcome to tours!'}, 
									 {link: '/tours', users: 20, name: 'Tours', desc: 'Welcome to tours!'} ];
		const roomList = rooms.map(room => roomInfo(room.link, room.users, room.name, room.desc) );
		return <PSPanelWrapper room={this.props.room}>
			<div class="mainmessage">
				<tbody>
					<tr>
						<td>
							<span 
								style="background:transparent url(https://play.pokemonshowdown.com/sprites/smicons-sheet.png?a5) no-repeat scroll -0px -2790px;" 
								class="picon icon-left" 
								title="Meloetta is PS's mascot! The Aria forme is about using its voice, and represents our chatrooms.">
							</span> 
							<button class="button" name="finduser" title="Find an online user"><strong>9920</strong> users online</button>
						</td>
						<td>
							<button class="button" name="roomlist" title="Watch an active battle"><strong>1512</strong> active battles</button> 
							<span 
								style="background:transparent url(https://play.pokemonshowdown.com/sprites/smicons-sheet.png?a5) no-repeat scroll -0px -2220px" 
								class="picon icon-right" 
								title="Meloetta is PS's mascot! The Pirouette forme is Fighting-type, and represents our battles.">
							</span>
						</td>
					</tr>
				</tbody>
				{roomList}
			</div>
		</PSPanelWrapper>;
	}
}

PS.roomTypes['rooms'] = {
	Model: RoomsRoom,
	Component: RoomsPanel,
};
PS.updateRoomTypes();
